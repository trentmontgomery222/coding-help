#!/bin/bash
SRC=/home/user/coding-help/wpcode-bb-values
S=/tmp/claude-0/-home-user-coding-help/082331c1-6daa-54d2-a0e9-ce835ff3acad/scratchpad
FILES=$(cd $SRC && find . -name '*.php' -o -name '*.js' -o -name '*.css' | sed 's|^\./||' | sort)
run() { php -d display_errors=1 -d error_reporting=E_ALL $S/harness/boot-once.php "$1" 2>&1 | grep -v "Deprecated" | tr '\n' ' '; echo; }
echo "=== baseline (nothing broken) ==="
rm -rf $S/victim && cp -r $SRC $S/victim && printf "  %-52s " "intact" && run $S/victim
for mode in delete corrupt; do
  echo
  echo "=== each file $mode-ed, one at a time ==="
  for f in $FILES; do
    rm -rf $S/victim && cp -r $SRC $S/victim
    if [ "$mode" = "delete" ]; then rm -f "$S/victim/$f"; else
      case "$f" in *.php) printf '<?php\nthis is not valid php {{{\n' > "$S/victim/$f";; *) printf '\x00\x01 not valid\n' > "$S/victim/$f";; esac
    fi
    printf "  %-52s " "$f"
    out=$(run $S/victim)
    if echo "$out" | grep -qi "fatal\|parse error\|SURVIVED"; then
      if echo "$out" | grep -qi "SURVIVED\|MAIN FILE GONE"; then echo "$out"; else echo "*** CRASH *** $out"; fi
    else echo "*** NO OUTPUT *** $out"; fi
  done
done
