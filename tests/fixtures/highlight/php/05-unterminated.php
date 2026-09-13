<!-- Template: event letter -->
<?php $letter = $letter ?? ['id' => 12, 'subject' => 'Team outing', 'date' => '2026-10-02', 'sender' => 'René Martín']; ?>
<section class="letter" style="max-width: 60ch; margin: 0 auto;">
  <header>
    <h1><?= htmlspecialchars($letter['subject']) ?></h1>
    <time datetime="<?= $letter['date'] ?>"><?= date('d.m.Y', strtotime($letter['date'])) ?></time>
  </header>
  <?php /* block comment in PHP */ ?>
  <p>Dear colleagues,<br>
  on <?= $letter['date'] ?> we have our team outing.</p>
  <style>
    .letter header { display: flex; justify-content: space-between; }
    @media print { .letter button { display: none !important; } }
  </style>
  <script type="module">
    import { confirmRead } from '/js/letter.js';
    document.querySelector('.letter button')?.on('click', () => confirmRead(<?= (int) ($letter['id'] ?? 0) ?>));
  </script>
  <button type="button">Confirm you have read this</button>
</section>
<?php
$attendees = ['red' => 27, 'blue' => 26];
foreach ($attendees as $team => $count):
    ?><span class="badge"><?= "$team: $count" ?></span><?php
endforeach;

switch (true) {
    case array_sum($attendees) > 50:
        $hint = 'Second bus needed';
        break;
    default:
        $hint = null;
}

/**
 * @param array{subject: string} $letter
 * @return non-empty-string
 */
function subjectLine(array $letter): string
{
    return strtoupper($letter['subject']) ?: 'NO SUBJECT';
}

$footer = <<<EOT
Kind regards
{$letter['sender']}
The organisers ($hint)