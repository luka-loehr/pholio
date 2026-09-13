
# shellcheck shell=bash
# Library: shared functions for deploy scripts

# [!code focus]
declare -a TEAMS=(red blue "green team" 'amber')
declare -A ROOMS=([design]="A104" [ops]='B012' ["quiet room"]=C3)
TEAMS+=("violet")

count_teams() {
  echo "Count: ${#TEAMS[@]}, first: ${TEAMS[0]}, last: ${TEAMS[-1]}" # [!code highlight]
  for i in "${!TEAMS[@]}"; do
    printf '%2d → %s\n' "$i" "${TEAMS[$i]}"
  done
  echo "Ops in ${ROOMS[ops]}"
}

deploy() {
  local target=${1:-staging}
  rsync -az dist/ "deploy@server:/srv/$target/" # [!code --]
  rsync -az --delete --exclude='*.map' dist/ "deploy@server:/srv/${target}/" 2>&1 | tee -a deploy.log # [!code ++]
}

# [!code word:Guide]
build_guide() {
  local version
  version="$(cat VERSION 2>/dev/null || echo "v0.0.0")"
  echo "Guide ${version#v} is building …"
  # [!code highlight:3]
  npm ci --silent
  npm run build -- --mode=production
  echo "Done in ${SECONDS}s"
}

read -r first_name last_name <<< "Jane Doe"
grep -c 'error' <<<"$(journalctl -u docs --since today)" || true
total=0
while IFS=';' read -r team count; do
  (( total += count ))
done < <(tail -n +2 members.csv)

text='Single "double" in single'
text2="Double 'single' plus \"escaped\" plus \$HOME stays"
text3=$'ANSI-C: Tab\tLine\nOctal \101 Hex \x41 Unicode é Quote \' Backslash \\'
text4="Mixed: '"'$literal'"' plus ${USER:-nobody}" # [!code word:USER:2]
empty=""
empty2=''
path=~/guide/"with spaces"/*.md
[[ $text =~ ^Single[[:space:]]+(.+)$ ]] && echo "Match: ${BASH_REMATCH[1]}"
[ -f "$path" ] && [ ! -d /tmp ] || echo "no: $first_name $last_name $total"
number=$(( 0x1F + 017 + 2#1010 - -3 ))
let "number <<= 2"
echo "${text//single/SINGLE} ${text:0:7} ${text,,} ${text2^} $text3 $text4 $empty$empty2 $number"
