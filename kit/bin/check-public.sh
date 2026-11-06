#!/bin/bash
# check-public.sh <plugin-repo> [ref] - readiness of a repository for a public push. Read-only.
# Patterns of private names, and of attribution, live outside any repository (~/.config/git-privacy/blocklist), never in
# this file: a tracked list of what to hide is itself the leak.
set -uo pipefail
D=$(cd "$1" && pwd); REF=${2:-HEAD}; cd "$D"
fail=0; ok() { echo "ok   $*"; }; bad() { echo "FAIL $*"; fail=1; }
BL=$HOME/.config/git-privacy/blocklist
PATS=(-e '192\.168\.' -e '/home/[a-z]')
for f in "$BL" "$HOME/.config/git-privacy/attribution"; do
  if [ -r "$f" ]; then while IFS= read -r p; do [ -n "$p" ] && PATS+=(-e "$p"); done <"$f"; ok "read $f"
  else bad "no pattern file $f"; fi
done
EXCL=(':!packages' ':!*.png' ':!*.txz')
hits=$(git grep -n -I -i -E "${PATS[@]}" "$REF" -- . "${EXCL[@]}" 2>/dev/null | cut -c1-160)
hits+=$(git grep -n -I -w -E 'OWNER' "$REF" -- . ':!kit' "${EXCL[@]}" 2>/dev/null | cut -c1-160)
[ -z "$hits" ] && ok "no private names, addresses or attribution in the tree at $REF" || { bad "tree:"; echo "$hits" | head -20; }
msgs=$(git log --format='%h %B' "$REF" | grep -i -E "${PATS[@]}" | cut -c1-160)
[ -z "$msgs" ] && ok "no private names in commit messages" || { bad "messages:"; echo "$msgs" | head; }
who=$(git log --format='%an <%ae> | %cn <%ce>' "$REF" | sort -u | grep -v '^dev <dev@localhost> | dev <dev@localhost>$')
[ -z "$who" ] && ok "every commit is dev <dev@localhost>" || { bad "identity:"; echo "$who" | head; }
tracked=$(git ls-tree -r --name-only "$REF" | grep -E '(^|/)(\.cl[a]ude|CL[A]UDE\.md|\.githooks|__pycache__)(/|$)|\.pyc$')
[ -z "$tracked" ] && ok "no tooling or bytecode tracked" || { bad "tracked:"; echo "$tracked"; }
for f in README.md LICENSE CHANGELOG.md plugin.conf icon.png kit/LICENSE; do git cat-file -e "$REF:$f" 2>/dev/null && ok "$f present" || bad "$f missing"; done
for f in LICENSE kit/LICENSE; do git show "$REF:$f" 2>/dev/null | grep -q 'GNU GENERAL PUBLIC LICENSE' && git show "$REF:$f" | grep -q 'Version 2, June 1991' && ok "$f is the GPL, version 2" || bad "$f is not the GPL, version 2"; done
for f in $(git ls-tree --name-only "$REF" packages/ 2>/dev/null | grep '\.txz$'); do
  list=$(git show "$REF:$f" | tar -tJ 2>/dev/null)   # the whole list first: grep -q would stop the pipe, and pipefail reports that as a failure
  grep -q '/LICENSE$' <<<"$list" && ok "$f carries the licence" || bad "$f has no licence"
done
git grep -q -i -E 'GPL|General Public License' "$REF" -- README.md && ok "README names the licence" || bad "README does not name the licence"
exit $fail
