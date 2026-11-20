#!/usr/bin/env bash
set -Eeuo pipefail

if [[ $EUID -ne 0 ]]; then
    echo 'Run with sudo: sudo bash ~/pharmacy-lab/scripts/apply.sh' >&2
    exit 1
fi

project_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
php -l "$project_dir/src/stock.php"
php -l "$project_dir/public/api.php"
nginx -t
php-fpm -t

install -d -o root -g root -m 0700 /var/backups/pharmacy-lab
backup_dir="$(mktemp -d /var/backups/pharmacy-lab/update.XXXXXXXX)"
cp -a /var/www/pharmacy/public /var/www/pharmacy/src "$backup_dir/"

rollback() {
    trap - ERR
    echo "Update failed; restoring application files from $backup_dir" >&2
    cp -a "$backup_dir/public" "$backup_dir/src" /var/www/pharmacy/
    restorecon -R /var/www/pharmacy/public /var/www/pharmacy/src
    systemctl reload php-fpm
}
trap rollback ERR

install -o root -g root -m 0644 "$project_dir/src/stock.php" /var/www/pharmacy/src/stock.php
install -o root -g root -m 0644 "$project_dir/public/api.php" /var/www/pharmacy/public/api.php
for file in index.html app.js style.css; do
    install -o root -g root -m 0644 "$project_dir/public/$file" "/var/www/pharmacy/public/$file"
done
restorecon -R /var/www/pharmacy/public /var/www/pharmacy/src
systemctl reload php-fpm
verified=0
for attempt in 1 2 3 4 5; do
    if curl --fail --silent --show-error --max-time 5 'http://127.0.0.1/api/stock?balance_id=1' \
        | python3 -c 'import sys, xml.etree.ElementTree as E; r=E.parse(sys.stdin).getroot(); assert r.tag=="stock_balances"; assert len(r)<=1 and all(x.findtext("balance_id")=="1" for x in r)' 2>/dev/null; then
        verified=1
        break
    fi
    sleep 1
done
[[ $verified -eq 1 ]]
curl --fail --silent --show-error --max-time 5 http://127.0.0.1/ \
    | grep -q 'id="request-url"'

trap - ERR
printf 'Application updated. Backup: %s\n' "$backup_dir"
