<?php
/**
 * Plugin Name:       Recurring Posts
 * Description:       Wydarzenia cykliczne dla Pie Calendar: automatycznie tworzy kopie posta-wydarzenia (co tydzień lub co miesiąc) z przesuniętymi datami.
 * Version:           1.1.5
 * Author:            Tomek
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * License:           GPLv2 or later
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Recurring_Posts {

	/** Number of future occurrences kept as published copies. */
	public const AHEAD = 4;

	public const CRON_HOOK = 'recpost_daily';

	// Template meta.
	private const M_RULE    = '_recpost_rule';
	private const M_OWNER   = '_recpost_owner';   // ID of the post the rule belongs to (guards against duplicators copying the rule).
	private const M_UNTIL   = '_recpost_until';   // Y-m-d, optional.
	private const M_SKIPPED = '_recpost_skipped'; // Y-m-d, multiple values: occurrences whose copy was trashed.

	// Copy meta.
	private const M_PARENT = '_recpost_parent';
	private const M_SELF   = '_recpost_self';     // ID of the copy itself (guards against duplicators).
	private const M_OCC    = '_recpost_occurrence';

	// Pie Calendar meta.
	private const PC_IS_EVENT = '_piecal_is_event';
	private const PC_START    = '_piecal_start_date';
	private const PC_END      = '_piecal_end_date';

	private const RULES = [
		'weekly'          => 'Co tydzień',
		'monthly_day'     => 'Co miesiąc – ten sam dzień miesiąca (np. 15.)',
		'monthly_weekday' => 'Co miesiąc – ten sam dzień tygodnia (np. 1. niedziela)',
	];

	/** Meta keys never copied from the template to a copy. */
	private const META_EXCLUDE = [
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_wp_desired_post_slug',
		'_encloseme',
		'_pingme',
		'_dp_original',
		'_dp_is_rewrite_republish_copy',
		'_dp_has_rewrite_republish_copy',
	];
	private const META_EXCLUDE_PREFIXES = [ '_recpost_', '_wpas_', '_publicize' ];

	private const NONCE = 'recpost_metabox';

	/** @var array<int,bool> Templates whose future copies should be rebuilt in this request. */
	private static array $refresh = [];

	/** True while the plugin itself trashes copies (so they are not recorded as skipped). */
	private static bool $internal_trash = false;

	public static function init(): void {
		add_action( 'init', [ self::class, 'ensure_cron' ] );
		add_action( self::CRON_HOOK, [ self::class, 'run_all' ] );
		add_action( 'add_meta_boxes_post', [ self::class, 'add_metabox' ] );
		add_action( 'save_post_post', [ self::class, 'save_metabox' ], 10, 2 );
		add_action( 'wp_after_insert_post', [ self::class, 'after_insert' ], 20, 2 );
		add_action( 'wp_trash_post', [ self::class, 'on_trash' ] );
		add_action( 'before_delete_post', [ self::class, 'on_trash' ] );
		add_action( 'untrashed_post', [ self::class, 'on_untrash' ] );
		add_filter( 'display_post_states', [ self::class, 'post_states' ], 10, 2 );
	}

	/* ------------------------------------------------------------------ */
	/* Posts list labels                                                   */
	/* ------------------------------------------------------------------ */

	private const RULE_SHORT = [
		'weekly'          => 'co tydzień',
		'monthly_day'     => 'co miesiąc (ten sam dzień)',
		'monthly_weekday' => 'co miesiąc (ten sam dzień tygodnia)',
	];

	/**
	 * Adds "Powtarzanie: …" / "Termin: …" after the title in the admin posts list,
	 * only for recurring templates and their copies.
	 *
	 * @param array<string,string> $states
	 * @return array<string,string>
	 */
	public static function post_states( array $states, WP_Post $post ): array {
		if ( 'post' !== $post->post_type ) {
			return $states;
		}
		$rule = self::get_rule( $post->ID );
		if ( '' === $rule && ! self::is_copy( $post->ID ) ) {
			return $states;
		}
		if ( '' !== $rule ) {
			$states['recpost_rule'] = 'Powtarzanie: ' . self::RULE_SHORT[ $rule ];
		}
		$start = self::parse_date( (string) get_post_meta( $post->ID, self::PC_START, true ) );
		if ( $start ) {
			$all_day = (bool) get_post_meta( $post->ID, '_piecal_is_allday', true );
			$states['recpost_date'] = 'Termin: ' . wp_date( $all_day ? 'D j.m.Y' : 'D j.m.Y, H:i', $start->getTimestamp() );
		}
		return $states;
	}

	/* ------------------------------------------------------------------ */
	/* Cron                                                                */
	/* ------------------------------------------------------------------ */

	public static function ensure_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'daily', self::CRON_HOOK );
		}
	}

	public static function activate(): void {
		self::ensure_cron();
		self::run_all();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function run_all(): void {
		$ids = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [
				[
					'key'     => self::M_RULE,
					'value'   => array_keys( self::RULES ),
					'compare' => 'IN',
				],
			],
		] );
		foreach ( $ids as $id ) {
			self::generate( (int) $id );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	public static function get_rule( int $post_id ): string {
		$rule = (string) get_post_meta( $post_id, self::M_RULE, true );
		if ( ! isset( self::RULES[ $rule ] ) ) {
			return '';
		}
		return (int) get_post_meta( $post_id, self::M_OWNER, true ) === $post_id ? $rule : '';
	}

	public static function is_copy( int $post_id ): bool {
		return (int) get_post_meta( $post_id, self::M_PARENT, true ) > 0
			&& (int) get_post_meta( $post_id, self::M_SELF, true ) === $post_id;
	}

	private static function parse_date( string $value ): ?DateTimeImmutable {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		try {
			return new DateTimeImmutable( $value, wp_timezone() );
		} catch ( Exception ) {
			return null;
		}
	}

	/** Format a date the same way the original Pie Calendar value was formatted. */
	private static function format_like( DateTimeImmutable $date, string $original ): string {
		$with_seconds = (bool) preg_match( '/\d{2}:\d{2}:\d{2}/', $original );
		$separator    = str_contains( $original, 'T' ) ? '\T' : ' ';
		if ( ! preg_match( '/\d{2}:\d{2}/', $original ) ) {
			return $date->format( 'Y-m-d' );
		}
		return $date->format( 'Y-m-d' . $separator . ( $with_seconds ? 'H:i:s' : 'H:i' ) );
	}

	/**
	 * The k-th occurrence after $start (k >= 1), or null when that month has no matching day.
	 */
	public static function nth_occurrence( DateTimeImmutable $start, string $rule, int $k ): ?DateTimeImmutable {
		if ( 'weekly' === $rule ) {
			return $start->add( new DateInterval( 'P' . ( 7 * $k ) . 'D' ) );
		}

		$time        = $start->format( 'H:i:s' );
		$month_first = $start->modify( 'first day of this month' )->modify( "+{$k} months" );
		$year        = (int) $month_first->format( 'Y' );
		$month       = (int) $month_first->format( 'n' );
		$days_in     = (int) $month_first->format( 't' );

		if ( 'monthly_day' === $rule ) {
			$day = (int) $start->format( 'j' );
			if ( $day > $days_in ) {
				return null;
			}
		} elseif ( 'monthly_weekday' === $rule ) {
			$weekday = (int) $start->format( 'N' );                // 1 = Mon … 7 = Sun
			$nth     = (int) ceil( (int) $start->format( 'j' ) / 7 ); // 1…5; 5 means "last"
			if ( $nth >= 5 ) {
				$last_wd = (int) $month_first->setDate( $year, $month, $days_in )->format( 'N' );
				$day     = $days_in - ( ( $last_wd - $weekday + 7 ) % 7 );
			} else {
				$first_wd = (int) $month_first->format( 'N' );
				$day      = 1 + ( ( $weekday - $first_wd + 7 ) % 7 ) + ( $nth - 1 ) * 7;
			}
		} else {
			return null;
		}

		[ $h, $i, $s ] = array_map( 'intval', explode( ':', $time ) );
		return $month_first->setDate( $year, $month, $day )->setTime( $h, $i, $s );
	}

	/**
	 * Copies of a template, keyed by occurrence date (Y-m-d).
	 *
	 * @return array<string,int>
	 */
	private static function get_copies( int $template_id, array $statuses = [ 'publish', 'future', 'draft', 'pending', 'private' ] ): array {
		$ids = get_posts( [
			'post_type'      => 'post',
			'post_status'    => $statuses,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => self::M_PARENT,
			'meta_value'     => (string) $template_id,
		] );
		$copies = [];
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( self::is_copy( $id ) ) {
				$copies[ (string) get_post_meta( $id, self::M_OCC, true ) ] = $id;
			}
		}
		ksort( $copies );
		return $copies;
	}

	/* ------------------------------------------------------------------ */
	/* Generation                                                          */
	/* ------------------------------------------------------------------ */

	public static function generate( int $template_id ): void {
		$template = get_post( $template_id );
		if ( ! $template || 'post' !== $template->post_type || 'publish' !== $template->post_status ) {
			return;
		}
		$rule = self::get_rule( $template_id );
		if ( '' === $rule || ! get_post_meta( $template_id, self::PC_IS_EVENT, true ) ) {
			return;
		}

		$start_raw = (string) get_post_meta( $template_id, self::PC_START, true );
		$end_raw   = (string) get_post_meta( $template_id, self::PC_END, true );
		$start     = self::parse_date( $start_raw );
		if ( ! $start ) {
			return;
		}
		$end   = self::parse_date( $end_raw );
		$until = self::parse_date( (string) get_post_meta( $template_id, self::M_UNTIL, true ) );
		$until = $until?->setTime( 23, 59, 59 );

		$skipped  = array_map( 'strval', (array) get_post_meta( $template_id, self::M_SKIPPED, false ) );
		$existing = self::get_copies( $template_id );
		$today    = new DateTimeImmutable( 'today', wp_timezone() );

		$future    = 0;
		$to_create = [];
		for ( $k = 1; $k <= 2000 && $future < self::AHEAD; $k++ ) {
			$occ = self::nth_occurrence( $start, $rule, $k );
			if ( null === $occ ) {
				continue;
			}
			if ( $until && $occ > $until ) {
				break;
			}
			if ( $occ < $today ) {
				continue;
			}
			$future++;
			$key = $occ->format( 'Y-m-d' );
			if ( isset( $existing[ $key ] ) || in_array( $key, $skipped, true ) ) {
				continue;
			}
			$to_create[] = $occ;
		}

		// Publication times 1 minute apart (the posts list shows minutes), in event order
		// and never in the future (a future time would make WordPress schedule the post
		// instead of publishing it).
		if ( ! $to_create ) {
			return;
		}
		$publish_ts = time() - MINUTE_IN_SECONDS * count( $to_create );

		// The template must stay the oldest post of the series: when it was published only
		// moments ago, move its publication time to one minute before the first copy.
		$template_ts = (int) strtotime( $template->post_date_gmt . ' UTC' );
		if ( $template_ts > $publish_ts ) {
			self::set_publish_time( $template->ID, $publish_ts );
		}

		foreach ( $to_create as $occ ) {
			$publish_ts += MINUTE_IN_SECONDS;
			$new_end     = $end ? $occ->add( $start->diff( $end ) ) : null;
			self::create_copy( $template, $occ, $new_end, $start_raw, $end_raw, $publish_ts );
		}
	}

	/** Change the publication time without triggering save hooks. */
	private static function set_publish_time( int $post_id, int $timestamp ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[
				'post_date'     => wp_date( 'Y-m-d H:i:s', $timestamp ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $timestamp ),
			],
			[ 'ID' => $post_id ]
		);
		clean_post_cache( $post_id );
	}

	private static function create_copy( WP_Post $template, DateTimeImmutable $start, ?DateTimeImmutable $end, string $start_raw, string $end_raw, int $publish_ts ): int {
		$copy_id = wp_insert_post( [
			'post_type'      => $template->post_type,
			'post_status'    => 'publish',
			'post_date'      => wp_date( 'Y-m-d H:i:s', $publish_ts ),
			'post_date_gmt'  => gmdate( 'Y-m-d H:i:s', $publish_ts ),
			'post_author'    => (int) $template->post_author,
			'post_title'     => $template->post_title,
			'post_content'   => $template->post_content,
			'post_excerpt'   => $template->post_excerpt,
			'post_password'  => $template->post_password,
			'comment_status' => $template->comment_status,
			'ping_status'    => $template->ping_status,
			'menu_order'     => $template->menu_order,
		], true );

		if ( is_wp_error( $copy_id ) || ! $copy_id ) {
			return 0;
		}

		// Taxonomies: categories, tags, post format, …
		foreach ( get_object_taxonomies( $template->post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $template->ID, $taxonomy, [ 'fields' => 'ids' ] );
			if ( ! is_wp_error( $terms ) ) {
				wp_set_object_terms( $copy_id, array_map( 'intval', $terms ), $taxonomy );
			}
		}

		// Meta (featured image, block CSS, Pie Calendar flags, …).
		foreach ( get_post_meta( $template->ID ) as $key => $values ) {
			if ( self::is_meta_excluded( (string) $key ) ) {
				continue;
			}
			delete_post_meta( $copy_id, $key );
			foreach ( $values as $value ) {
				add_post_meta( $copy_id, $key, maybe_unserialize( $value ) );
			}
		}

		// Shifted Pie Calendar dates (set last, so nothing else overwrites them).
		update_post_meta( $copy_id, self::PC_START, self::format_like( $start, $start_raw ) );
		update_post_meta( $copy_id, self::PC_END, $end ? self::format_like( $end, $end_raw ) : '' );

		update_post_meta( $copy_id, self::M_PARENT, $template->ID );
		update_post_meta( $copy_id, self::M_SELF, $copy_id );
		update_post_meta( $copy_id, self::M_OCC, $start->format( 'Y-m-d' ) );

		return (int) $copy_id;
	}

	private static function is_meta_excluded( string $key ): bool {
		if ( in_array( $key, self::META_EXCLUDE, true ) ) {
			return true;
		}
		foreach ( self::META_EXCLUDE_PREFIXES as $prefix ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/** Trash all future copies and forget skipped dates, so generate() rebuilds them from the template. */
	private static function refresh( int $template_id ): void {
		$today = ( new DateTimeImmutable( 'today', wp_timezone() ) )->format( 'Y-m-d' );

		self::$internal_trash = true;
		foreach ( self::get_copies( $template_id ) as $occ => $copy_id ) {
			if ( $occ >= $today ) {
				wp_trash_post( $copy_id );
			}
		}
		self::$internal_trash = false;

		delete_post_meta( $template_id, self::M_SKIPPED );
	}

	/* ------------------------------------------------------------------ */
	/* Hooks                                                               */
	/* ------------------------------------------------------------------ */

	public static function after_insert( int $post_id, WP_Post $post ): void {
		if ( 'post' !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! empty( self::$refresh[ $post_id ] ) ) {
			unset( self::$refresh[ $post_id ] );
			self::refresh( $post_id );
		}
		self::generate( $post_id );
	}

	/** A trashed/deleted copy is remembered, so the daily run does not recreate it. */
	public static function on_trash( int $post_id ): void {
		if ( self::$internal_trash || ! self::is_copy( $post_id ) ) {
			return;
		}
		$parent = (int) get_post_meta( $post_id, self::M_PARENT, true );
		$occ    = (string) get_post_meta( $post_id, self::M_OCC, true );
		$known  = array_map( 'strval', (array) get_post_meta( $parent, self::M_SKIPPED, false ) );
		if ( $occ && ! in_array( $occ, $known, true ) ) {
			add_post_meta( $parent, self::M_SKIPPED, $occ );
		}
	}

	public static function on_untrash( int $post_id ): void {
		if ( ! self::is_copy( $post_id ) ) {
			return;
		}
		$parent = (int) get_post_meta( $post_id, self::M_PARENT, true );
		$occ    = (string) get_post_meta( $post_id, self::M_OCC, true );
		delete_post_meta( $parent, self::M_SKIPPED, $occ );
	}

	/* ------------------------------------------------------------------ */
	/* Metabox                                                             */
	/* ------------------------------------------------------------------ */

	public static function add_metabox(): void {
		add_meta_box( 'recpost-metabox', 'Wydarzenie cykliczne', [ self::class, 'render_metabox' ], 'post', 'side', 'default' );
	}

	public static function render_metabox( WP_Post $post ): void {
		if ( self::is_copy( $post->ID ) ) {
			$parent = (int) get_post_meta( $post->ID, self::M_PARENT, true );
			echo '<p>To jest automatyczna kopia wydarzenia cyklicznego.</p>';
			if ( get_post( $parent ) ) {
				printf(
					'<p><a href="%s">Edytuj wzorzec: %s</a></p>',
					esc_url( (string) get_edit_post_link( $parent ) ),
					esc_html( get_the_title( $parent ) )
				);
			}
			echo '<p class="description">Zmiany w tej kopii dotyczą tylko tego jednego terminu. Przeniesienie kopii do kosza odwołuje ten termin (nie zostanie utworzony ponownie).</p>';
			return;
		}

		$rule  = self::get_rule( $post->ID );
		$until = (string) get_post_meta( $post->ID, self::M_UNTIL, true );
		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<p>
			<label for="recpost_rule"><strong>Powtarzaj</strong></label><br>
			<select name="recpost_rule" id="recpost_rule" style="width:100%">
				<option value="">Nie powtarzaj</option>
				<?php foreach ( self::RULES as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $rule, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="recpost_until"><strong>Powtarzaj do</strong> (opcjonalnie)</label><br>
			<input type="date" name="recpost_until" id="recpost_until" value="<?php echo esc_attr( $until ); ?>" style="width:100%">
		</p>
		<p>
			<label>
				<input type="checkbox" name="recpost_refresh" value="1">
				Utwórz przyszłe kopie od nowa
			</label><br>
			<span class="description">Przenosi przyszłe kopie do kosza i tworzy je ponownie z aktualnej treści i daty wzorca. Przywraca też odwołane terminy.</span>
		</p>
		<p class="description">
			Kopie powstają na <?php echo (int) self::AHEAD; ?> kolejne terminy, liczone od daty wydarzenia ustawionej w Pie Calendar. Działa tylko dla opublikowanych postów oznaczonych jako wydarzenie.
		</p>
		<?php
		if ( '' !== $rule ) {
			$today  = ( new DateTimeImmutable( 'today', wp_timezone() ) )->format( 'Y-m-d' );
			$copies = array_filter( self::get_copies( $post->ID ), static fn( $occ ) => $occ >= $today, ARRAY_FILTER_USE_KEY );
			if ( $copies ) {
				echo '<p><strong>Przyszłe kopie:</strong></p><ul style="margin-top:0">';
				foreach ( $copies as $occ => $copy_id ) {
					printf(
						'<li><a href="%s">%s</a></li>',
						esc_url( (string) get_edit_post_link( $copy_id ) ),
						esc_html( wp_date( 'D, j.m.Y', strtotime( $occ ) ?: null ) )
					);
				}
				echo '</ul>';
			}
			$skipped = (array) get_post_meta( $post->ID, self::M_SKIPPED, false );
			if ( $skipped ) {
				sort( $skipped );
				echo '<p><strong>Odwołane terminy:</strong> ' . esc_html( implode( ', ', $skipped ) ) . '</p>';
			}
		}
	}

	public static function save_metabox( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		// Only the post being edited (not copies created during this request).
		if ( (int) ( $_POST['post_ID'] ?? 0 ) !== $post_id ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( self::is_copy( $post_id ) ) {
			return;
		}

		$rule = sanitize_key( wp_unslash( $_POST['recpost_rule'] ?? '' ) );

		// Cancelled dates belong to this post only. If the meta came from another post
		// (copied by a duplicator), forget it. Turning recurrence off/on keeps them.
		if ( (int) get_post_meta( $post_id, self::M_OWNER, true ) !== $post_id ) {
			delete_post_meta( $post_id, self::M_SKIPPED );
		}

		if ( isset( self::RULES[ $rule ] ) ) {
			update_post_meta( $post_id, self::M_RULE, $rule );
			update_post_meta( $post_id, self::M_OWNER, $post_id );
		} else {
			delete_post_meta( $post_id, self::M_RULE );
		}

		$until = sanitize_text_field( wp_unslash( $_POST['recpost_until'] ?? '' ) );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until ) ) {
			update_post_meta( $post_id, self::M_UNTIL, $until );
		} else {
			delete_post_meta( $post_id, self::M_UNTIL );
		}

		if ( ! empty( $_POST['recpost_refresh'] ) ) {
			self::$refresh[ $post_id ] = true;
		}
	}
}

Recurring_Posts::init();
register_activation_hook( __FILE__, [ Recurring_Posts::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Recurring_Posts::class, 'deactivate' ] );
