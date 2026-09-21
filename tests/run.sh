#!/bin/sh
# Runs every *_test.php (needs only php-cli) and, if node is installed, every *_test.js.
cd "$(dirname "$0")" || exit 1
rc=0
for t in *_test.php; do
  php "$t" || rc=1
done
# the page's JavaScript logic is tested with node when it is available
if command -v node >/dev/null 2>&1; then
  for t in *_test.js; do
    node "$t" || rc=1
  done
else
  echo "node not found: skipping the JavaScript tests"
fi
exit $rc
