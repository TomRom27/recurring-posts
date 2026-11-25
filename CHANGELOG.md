# Changelog

## 1.1.5

First public release.

- Weekly and monthly recurrence for Pie Calendar event posts. Monthly recurrence can use the same day of the month or the same weekday of the month (e.g. the 1st Sunday, or the last Monday).
- 4 future occurrences are kept as published copies. They are created when the template is saved, and the next one is added by a daily WP-Cron job.
- Copies carry the template's content, featured image, categories, tags and meta, with the Pie Calendar dates moved to the occurrence.
- Optional end date („Powtarzaj do”).
- Trashing a copy cancels that occurrence. Restoring it from the trash removes the cancellation.
- „Utwórz przyszłe kopie od nowa” rebuilds future copies from the template.
- Publication times one minute apart, so the series is ordered in the posts list, with the template always the oldest.
- Labels in the posts list showing the recurrence and the occurrence date.
- Recurrence settings and copy markers are ignored on posts cloned by Duplicate Post or similar plugins.
