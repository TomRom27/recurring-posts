# Recurring Posts

A small WordPress plugin that adds weekly and monthly recurrence to [Pie Calendar](https://wordpress.org/plugins/pie-calendar/) events. Pie Calendar stays unchanged.

The plugin does not add a recurrence engine. It publishes ordinary copies of an event post, with the Pie Calendar dates moved to each new occurrence. The copies show up in the calendar and in the posts list like any other post. Other plugins and widgets that read Pie Calendar meta also see them.

## Requirements

- WordPress 6.5+
- PHP 8.1+
- Pie Calendar (free version)

## Installation

Download the plugin as a zip with the `recurring-posts/` folder inside. In WordPress, go to *Plugins → Add New → Upload Plugin*, upload it and activate.

## Usage

1. Create or edit a post and mark it as a Pie Calendar event with a start date. This post is the **template**.
2. In the sidebar box **„Wydarzenie cykliczne”**, choose the recurrence:
   - **Co tydzień**: every week.
   - **Co miesiąc – ten sam dzień miesiąca**: every month on the same day, e.g. the 15th. A month without that day is skipped.
   - **Co miesiąc – ten sam dzień tygodnia**: every month on the same weekday, e.g. the 1st Sunday. If the template falls in the 5th week, the copy uses the last such weekday of the month.
3. Optionally set **„Powtarzaj do”** to stop the recurrence after a date.
4. Publish or update the post.

The plugin keeps **4 future occurrences** as published copies. Saving the template creates the missing copies right away. A daily WP-Cron job adds the next copy when an occurrence passes.

## What a copy contains

- Title, content, excerpt, author, featured image (poster), categories, tags and post format.
- All other post meta, except technical keys: edit lock, old slugs, Duplicate Post markers, Jetpack Publicize data, and the plugin's own keys.
- The Pie Calendar start and end dates moved to the occurrence. The time of day and the duration are kept, and so is the original date format.

## Publication times

Copies created together get publication times one minute apart, in event order, and never in the future. A future time would make WordPress schedule the copy instead of publishing it.

If the template was published only moments ago, its publication time is moved to one minute before the first copy. The template always stays the oldest post of the series. The posts list then shows the series in order, with the furthest occurrence on top.

## Posts list labels

In *Posts → All Posts*, recurring posts get a label after the title:

- Template: „Powtarzanie: co tydzień, Termin: wt. 24.11.2026, 17:00”
- Copy: „Termin: wt. 1.12.2026, 17:00”

Ordinary posts are not changed.

## Cancelling and changing occurrences

- **Cancel one occurrence:** move its copy to the trash. The date is remembered as cancelled and the copy is not created again. Restoring the copy from the trash removes the cancellation.
- Turning recurrence off and on again keeps cancelled dates.
- **Change the template:** to apply a changed time, poster or text to the future copies, tick **„Utwórz przyszłe kopie od nowa”** and save. This moves the future copies to the trash, forgets cancelled dates and creates the copies again from the template. Past copies are not touched.
- **Edit one copy:** a copy can be edited on its own. The change applies only to that occurrence.

## Duplicate Post and similar plugins

A clone of a template or of a copy does **not** inherit the recurrence. It also does not count as a copy and does not inherit cancelled dates. To make a clone recurring, choose the recurrence in its sidebar box before publishing.

## Notes

- WP-Cron runs when the site is visited, so a new copy may appear a few hours after an occurrence passes. With 4 occurrences ahead this does not matter.
- Every copy is a new published post. If Jetpack, or another plugin, emails subscribers or shares new posts on social media, it will do so for each copy.

## Stored meta

| Key | On | Meaning |
|---|---|---|
| `_recpost_rule` | template | `weekly`, `monthly_day` or `monthly_weekday` |
| `_recpost_owner` | template | ID of the post that owns the rule (protects against duplicated meta) |
| `_recpost_until` | template | optional end date, `Y-m-d` |
| `_recpost_skipped` | template | cancelled occurrence dates, `Y-m-d`; may have several values |
| `_recpost_parent` | copy | template ID |
| `_recpost_self` | copy | copy's own ID (protects against duplicated meta) |
| `_recpost_occurrence` | copy | occurrence date, `Y-m-d` |

## License

GPLv2 or later.
