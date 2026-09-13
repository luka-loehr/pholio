<?php

declare(strict_types=1);

// Heredoc with SQL-like content
$team = 'blue';
$data = ['edition' => '2026/27', 'level' => ['min' => 5, 'max' => 13]];
$obj = new stdClass();
$obj->name = 'Muñoz';
$obj->address = new stdClass();
$obj->address->city = 'Lyon';

$sql = <<<SQL
    SELECT m.name, m.first_name, COUNT(a.id) AS absent_days, SUM(a.hours) AS hours, UPPER(m.code) AS code, CHAR(65) AS letter
    FROM member m
    LEFT JOIN absence a ON a.member_id = m.id
    WHERE m.team = '$team'
      AND m.edition = "{$data['edition']}"
      AND m.level BETWEEN {$data['level']['min']} AND {$data['level']['max']}
    GROUP BY m.id -- comment inside the heredoc
    SQL;

$html = <<<"HTML"
<div class="card" data-city="{$obj->address->city}">
  <h2>Hello $obj->name!</h2>
  <p>Escapes: \t tab, \\ backslash, \$none, \x41, \u{00E9}</p>
  <script>alert("just text");</script>
</div>
HTML;

$template = <<<'NOWDOC'
Here $nothing {$is} replaced and \n stays literal.
<?php echo 'no PHP either'; ?>
NOWDOC;

$emoji = "🎉 Gala – size: XL – Rue de la Paix – 𝔄";
$nbsp = "10 % off";
$café = 42;
$ñandú_variable = "{$café} cm";

$schedule = ['Algebra_0' => ['hours' => 1, 'room' => "R100"], 'Geometry_1' => ['hours' => 2, 'room' => "R101"], 'Astronomy_2' => ['hours' => 3, 'room' => "R102"], 'Façade Design_3' => ['hours' => 4, 'room' => "R103"], 'Botany_4' => ['hours' => 5, 'room' => "R104"], 'Physics_5' => ['hours' => 1, 'room' => "R105"], 'Chemistry_6' => ['hours' => 2, 'room' => "R106"], 'Biology_7' => ['hours' => 3, 'room' => "R107"], 'History_8' => ['hours' => 4, 'room' => "R108"], 'Civics_9' => ['hours' => 5, 'room' => "R109"], 'Geography_10' => ['hours' => 1, 'room' => "R110"], 'Fine Arts_11' => ['hours' => 2, 'room' => "R111"], 'Music_12' => ['hours' => 3, 'room' => "R112"], 'Sports_13' => ['hours' => 4, 'room' => "R113"], 'Computing_14' => ['hours' => 5, 'room' => "R114"], 'Philosophy_15' => ['hours' => 1, 'room' => "R115"], 'Ethics_16' => ['hours' => 2, 'room' => "R116"], 'Economics_17' => ['hours' => 3, 'room' => "R117"], 'Spanish_18' => ['hours' => 4, 'room' => "R118"], 'Linguistics_19' => ['hours' => 5, 'room' => "R119"], 'Robotics_20' => ['hours' => 1, 'room' => "R120"], 'Film & Theatre_21' => ['hours' => 2, 'room' => "R121"], 'Algebra_22' => ['hours' => 3, 'room' => "R122"], 'Geometry_23' => ['hours' => 4, 'room' => "R123"], 'Astronomy_24' => ['hours' => 5, 'room' => "R124"], 'Façade Design_25' => ['hours' => 1, 'room' => "R125"], 'Botany_26' => ['hours' => 2, 'room' => "R126"], 'Physics_27' => ['hours' => 3, 'room' => "R127"], 'Chemistry_28' => ['hours' => 4, 'room' => "R128"], 'Biology_29' => ['hours' => 5, 'room' => "R129"], 'History_30' => ['hours' => 1, 'room' => "R130"], 'Civics_31' => ['hours' => 2, 'room' => "R131"], 'Geography_32' => ['hours' => 3, 'room' => "R132"], 'Fine Arts_33' => ['hours' => 4, 'room' => "R133"], 'Music_34' => ['hours' => 5, 'room' => "R134"], 'Sports_35' => ['hours' => 1, 'room' => "R135"], 'Computing_36' => ['hours' => 2, 'room' => "R136"], 'Philosophy_37' => ['hours' => 3, 'room' => "R137"], 'Ethics_38' => ['hours' => 4, 'room' => "R138"], 'Economics_39' => ['hours' => 5, 'room' => "R139"], 'Spanish_40' => ['hours' => 1, 'room' => "R140"], 'Linguistics_41' => ['hours' => 2, 'room' => "R141"], 'Robotics_42' => ['hours' => 3, 'room' => "R142"], 'Film & Theatre_43' => ['hours' => 4, 'room' => "R143"], 'Algebra_44' => ['hours' => 5, 'room' => "R144"]];

function out(string ...$lines): void
{
    foreach ($lines as $l) {   
        echo $l, "\n";
    }
}
   


out($sql, $html, $template, $emoji, $nbsp, $ñandú_variable, (string) count($schedule));

printf(<<<TXT
    Team: %s
    Count: %d
    TXT, $team, 27);

