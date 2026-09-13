
<?php
declare(strict_types=1);

namespace Acme\Scheduling;

use DateTimeImmutable;
use InvalidArgumentException as Invalid;
use function sprintf;
use const PHP_EOL;

/**
 * Represents a single time slot.
 *
 * @package Acme\Scheduling
 * @author  Docs Team <team@example.com>
 * @property-read string $topic
 * @template T of array<string, mixed>
 */
final class Slot implements \JsonSerializable
{
	public const MAX = 10;
	private const COLOR = 0xFFA03C;
	protected const MODE = 0755;
	protected const MODE_NEW = 0o755;
	final public const MASK = 0b1010_0101;
	public const RATIO = 1_234.5e-3;

	/**
	 * @param int         $number Slot 1–10
	 * @param string|null $room   Room label or null
	 * @throws Invalid
	 */
	public function __construct(
		public readonly int $number,
		private string $topic = '',
		protected ?string $room = null,
		private ?DateTimeImmutable $date = null,
	) {
		if ($number < 1 || $number > self::MAX) { // check the range
			throw new Invalid("Invalid slot: {$number}");
		}
	}

	public function jsonSerialize(): array
	{
		return ['number' => $this->number, 'topic' => $this->topic, 'room' => $this->room ?? '—'];
	}
}

enum Weekday: int
{
	case Monday = 1;
	case Tuesday = 2;
	case Wednesday = 3;

	public function short(): string
	{
		return match ($this) {
			self::Monday => 'Mo',
			self::Tuesday => 'Tu',
			default => mb_substr($this->name, 0, 2),
		};
	}
}

$single = 'No $interpolation\n, but \' and \\ work';
$double = "Line\n\tIndented \"quoted\" \\ \$dollar \x41 \101 \u{1F389} é";
$empty = '' . "";
$negative = -42 + -0.5 - 1e10 + PHP_INT_MAX;
# Shell-style comment
$slot = new Slot(3, 'Algebra', 'A104');
echo sprintf('Slot %d: %s', $slot->number, json_encode($slot, JSON_THROW_ON_ERROR)), PHP_EOL;
