<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Background ADN synchronization

The command below is safe to run repeatedly from the Akaunting scheduler:

```bash
php artisan nfse:adn-sync <company-id>
```

Use `--max-pages=N` to bound work per execution. The default is 10 ADN batches
and the hard command limit is 100.

The command switches into the requested Akaunting company runtime before reading
NFS-e settings/certificates. Cursor state is isolated by company and
sandbox/production environment.

Each distributed ADN document is persisted idempotently. The checkpoint advances
only in the same database transaction that stores the whole returned batch. If a
run fails before that commit, the previous NSU remains and the next run safely
reprocesses the same batch.

Only explicitly mapped official events may change a local fiscal lifecycle.
Currently event `101101` (NFS-e cancellation) marks a matching local receipt as
cancelled. Unknown event types remain stored with `recognized_event=false` for
operator/developer review and never cause a guessed mutation.

The command only performs ADN reads. It does not issue, replace, or cancel an
NFS-e remotely.
