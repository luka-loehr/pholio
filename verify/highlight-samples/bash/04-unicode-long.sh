#!/usr/bin/env bash
# Unicode in scripts: café, naïve, façade – 🎉 – 𝔄𝔭𝔫𝔯𝔞𝔦𝔪 – 漢字

GREETING="Happy holidays! 🌞🏖️"
PRICE="12.50 €" # non-breaking space
café=42 # invalid variable name, read as a command
echo "$GREETING costs $PRICE"

TOPICS=("Algebra" "Geometry" "Astronomy" "Crème brûlée" "Botany" "Physics" "Chemistry" "Biology" "History" "Civics" "Geography" "Fine Arts" "Music" "Sports" "Computing" "Philosophy" "Ethics" "Economics" "Spanish" "Linguistics" "Robotics" "Film & Theatre" "Algebra" "Geometry" "Astronomy" "Crème brûlée" "Botany" "Physics" "Chemistry" "Biology" "History" "Civics" "Geography" "Fine Arts" "Music" "Sports" "Computing" "Philosophy" "Ethics" "Economics" "Spanish" "Linguistics" "Robotics" "Film & Theatre" "Algebra" "Geometry" "Astronomy" "Crème brûlée" "Botany" "Physics" "Chemistry" "Biology" "History" "Civics" "Geography" "Fine Arts" "Music" "Sports" "Computing" "Philosophy" "Ethics" "Economics" "Spanish" "Linguistics" "Robotics" "Film & Theatre" "Algebra" "Geometry" "Astronomy" "Crème brûlée" "Botany" "Physics" "Chemistry" "Biology" "History" "Civics" "Geography" "Fine Arts" "Music" "Sports" "Computing" "Philosophy" "Ethics" "Economics" "Spanish" "Linguistics" "Robotics" "Film & Theatre" "Algebra" "Geometry" "Astronomy" "Crème brûlée" "Botany" "Physics" "Chemistry" "Biology" "History" "Civics" "Geography" "Fine Arts" "Music" "Sports" "Computing" "Philosophy" "Ethics" "Economics" "Spanish" "Linguistics" "Robotics" "Film & Theatre" "Algebra" "Geometry" "Astronomy" "Crème brûlée" "Botany" "Physics" "Chemistry" "Biology" "History" "Civics" "Geography" "Fine Arts" "Music" "Sports" "Computing" "Philosophy" "Ethics" "Economics" "Spanish" "Linguistics" "Robotics" "Film & Theatre" "Algebra" "Geometry" "Astronomy" "Crème brûlée" "Botany" "Physics" "Chemistry" "Biology" "History" "Civics" "Geography" "Fine Arts" "Music" "Sports" "Computing" "Philosophy" "Ethics" "Economics" "Spanish" "Linguistics" "Robotics" "Film & Theatre" "Algebra" "Geometry" "Astronomy" "Crème brûlée" "Botany" "Physics" "Chemistry" "Biology" "History" "Civics" "Geography" "Fine Arts" "Music" "Sports" "Computing" "Philosophy" "Ethics" "Economics" "Spanish" "Linguistics" "Robotics" "Film & Theatre" "Algebra" "Geometry" "Astronomy" "Crème brûlée")

for topic in "${TOPICS[@]}"; do   
  slug=$(printf '%s' "$topic" | iconv -f utf-8 -t ascii//TRANSLIT | tr '[:upper:] ' '[:lower:]-' | tr -cd 'a-z0-9-')
  printf '%-30s → %s\n' "$topic" "$slug"
done
   



function show_emoji {
  local -r chars=(🎉 🎓 📚 ✏️ 🧪)
  local IFS=,
  echo "Emoji: ${chars[*]} (count: ${#chars[@]})"
}

show_emoji
select choice in Yes No "Later please"; do
  [[ -n $choice ]] && break
done
echo "Chosen: ${choice:-nothing}" | sed -E 's/(Yes|No)/[\1]/g; s/é/e/g'
printf '%s\n' "Accent test: éèêàçñøå ÉÈÊ" > >(tee -a unicode.log) 2> /dev/null
coproc SEARCH { grep --line-buffered 'café'; }
echo "$café" >&"${SEARCH[1]}"

