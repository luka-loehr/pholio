<?php

declare(strict_types=1);

namespace Acme\Api;

use Acme\Database\Connection;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Route
{
    public function __construct(public string $path, public array $methods = ['GET']) {}
}

// [!code focus]
final class TicketController
{
    private ?string $team = null;

    public function __construct(private readonly Connection $db) {}

    #[Route('/api/tickets', methods: ['POST'])]
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $data = (array) $request->getParsedBody();
        $author = trim($data['author'] ?? ''); // [!code highlight]
        if ($author === '') {
            return $this->json(['error' => 'Author missing'], 422);
        }

        // [!code word:ticket]
        $sql = 'INSERT INTO ticket (author, date) VALUES (?, ?)';
        $this->db->query("INSERT INTO ticket VALUES ('$author')"); // [!code --]
        $this->db->execute($sql, [$author, $data['date'] ?? date('Y-m-d')]); // [!code ++]

        // [!code highlight:3]
        $id = $this->db->lastId();
        $message = "Ticket #{$id} for {$data['author']} saved";
        $path = "/api/tickets/$id in team $this->team or {$this->db->name()} and $data[author]";

        return $this->json(['id' => $id, 'message' => $message, 'path' => $path], 201);
    }

    private function json(array $payload, int $status = 200): ResponseInterface
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); // [!code word:payload:2]
        return new \Nyholm\Psr7\Response($status, ['Content-Type' => 'application/json; charset=utf-8'], $body);
    }

    public static function filter(iterable $rows, ?callable $check = null): \Generator
    {
        $check ??= static fn (array $r): bool => !empty($r['active']);
        foreach ($rows as $key => $row) {
            if ($check($row)) {
                yield $key => $row;
            }
        }
    }
}

$result = array_filter([1, 2, 3], fn($n) => $n % 2 === 1);
['a' => $a, 'b' => $b] = ['a' => 1, 'b' => 2];
[$x, [$y, $z]] = [1, [2, 3]];
$text = <<<'TXT'
    Nowdoc: $no {$interpolation} \n stays as is
    TXT;
