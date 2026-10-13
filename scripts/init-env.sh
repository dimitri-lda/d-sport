#!/bin/sh
set -eu
if [ -f .env ]; then
    exit 0
fi
umask 077
secret=$(docker run --rm --entrypoint php composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac -r 'echo bin2hex(random_bytes(32));')
tmpfile=$(mktemp .env.init.XXXXXX)
trap 'rm -f "$tmpfile"' EXIT HUP INT TERM
sed "s/^APP_SECRET=.*/APP_SECRET=$secret/" .env.example > "$tmpfile"
mv "$tmpfile" .env
echo 'Created local .env with a generated APP_SECRET.'
