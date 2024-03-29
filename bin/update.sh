#!/bin/bash
#@author Fred Brooker <git@gscloud.cz>

dir="$(dirname "$0")"
. "$dir/_includes.sh"

# versioning
VERSION=$(git rev-parse HEAD)
echo $VERSION > VERSION
REVISIONS=$(git rev-list --all --count)
echo $REVISIONS > REVISIONS
info "Version: $VERSION Revisions: $REVISIONS"

# cleaning
rm -rf logs/* temp/*
ln -s ../. www/cdn-assets/$VERSION >/dev/null 2>&1
find www/cdn-assets/ -type l -mtime +30 -delete

# composer
command -v composer >/dev/null 2>&1 || fail "PHP composer is not installed!"
composer update --no-plugins --no-scripts
echo -en "\n"

# get beer prices HTML5 raw data + preprocess
d=$(date +'%Y%m%d')
if [ ! -f "akce-$d.data" ]; then
  # delete export of missing translations
  rm -f data/missing_translations.txt
  wget -O beer1.html 'https://www.kupi.cz/hledej?f=pivo&vse=0'
  for i in {2..8}; do wget -O "beer$i.html" 'https://www.kupi.cz/hledej?page='$i'&f=pivo&vse=0'; sleep 1; done
  # parse BOTTLED PRICES using Red + fix output
  cat beer*.html | tr '\n' ' ' | sed 's/<tr/\n<tr/g' | grep 'záloha' > akce.html
  ./akce | sed 's/&nbsp;/ /g' | sed 's/&ndash;//g' > akce.data
  # parse ALL PRICES using Red + fix output
  cat beer*.html | tr '\n' ' ' | sed 's/<tr/\n<tr/g' > akce.html
  ./akce | sed 's/&nbsp;/ /g' | sed 's/&ndash;//g' > akce-all.data
fi

# show discounts info
A=$(cat akce.data | grep '\-\-\-' | wc -l)
B=$(cat akce-all.data | grep '\-\-\-' | wc -l)
info "Discounts - bottles: $A, all: $B\n"

# data backups
cp akce.data akce-$d.data
cp akce-all.data akce-all-$d.data
mkdir -p akce_archiv/
cp akce-$d.data akce_archiv/
cp akce-all-$d.data akce_archiv/
git add -A

# clean up
info Cleaning
rm *.html >/dev/null 2>&1
sync
find . -maxdepth 1 -name "akce-[0-9]*.data" -mtime +10 -delete >/dev/null 2>&1
find . -maxdepth 1 -name "akce-all-[0-9]*.data" -mtime +10 -delete >/dev/null 2>&1

# CRLF normalization
git add --renormalize .

# commit changes
git commit -am "automatic update"
git push origin master

exit 0
