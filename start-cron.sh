#!/bin/sh
set -e

# Jobs run as www-data, an unprivileged user of the PHP image; only the cron daemon itself runs as root.
JOB_USER=www-data

# Cron starts jobs with an empty environment, so the container's variables (CRON_RUNNER_ADAPTER, but also anything
# the jobs need, like credentials) are saved to a file, which each cron run loads first.
# `export -p` quotes the values, so they can't break the file. Only the job user may read it, as it can contain secrets.
ENV_FILE=/etc/cron-runner.env
(umask 077 && export -p > "$ENV_FILE")
chown "$JOB_USER" "$ENV_FILE"

# Only root can write to the container's output (/proc/1/fd/1), so jobs write to a named pipe, which is copied there.
# Opening the pipe for reading and writing keeps it open between runs, so the copying doesn't stop after the first one.
OUTPUT_PIPE=/run/cron-runner.pipe
rm -f "$OUTPUT_PIPE"
mkfifo -m 0600 "$OUTPUT_PIPE"
chown "$JOB_USER" "$OUTPUT_PIPE"
cat <> "$OUTPUT_PIPE" &

printf '%s\n' \
    'PATH=/usr/local/bin:/usr/bin:/bin' \
    "* * * * * $JOB_USER . $ENV_FILE && cd /app && php index.php > $OUTPUT_PIPE 2>&1" \
    > /etc/cron.d/cron-runner
chmod 0644 /etc/cron.d/cron-runner

exec cron -f
