import { z } from "zod";

// Overloads
export function grade(points: number): string;
export function grade(points: number, trend: true): `${number}${"+" | "-" | ""}`;
export function grade(points: number, trend = false): string {
  const n = Math.min(6, Math.max(1, Math.round(6 - points / 3)));
  return trend ? `${n}${points % 3 === 0 ? "+" : ""}` : String(n);
}

const ScoreSchema = z.object({
  member: z.string().min(1, { message: "Name must not be empty" }),
  points: z.number().int().gte(0).lte(15),
});
export type ScoreEntry = z.infer<typeof ScoreSchema>;

export class Scorebook<T extends ScoreEntry = ScoreEntry> {
  private entries: T[] = [];
  public constructor(public readonly topic: string, protected half: 1 | 2 = 1) {}

  add(entry: T): this {
    this.entries.push(ScoreSchema.parse(entry) as T);
    return this;
  }

  average(): number | undefined {
    return this.entries.length ? this.entries.reduce((s, e) => s + e.points, 0) / this.entries.length : undefined;
  }
}

export async function importRows(rows: string[]): Promise<ScoreEntry[]> {
  const result: ScoreEntry[] = [];
  outer: for (const row of rows) {
    for (const char of row) {
      if (char === "#") continue outer;
    }
    try {
      const [member = "", points = "0"] = row.split(";");
      result.push(ScoreSchema.parse({ member, points: Number(points) }));
    } catch (error: unknown) {
      if (error instanceof z.ZodError) console.warn(error.issues);
      else throw error;
    } finally {
      await new Promise<void>((r) => setTimeout(r, 0));
    }
  }
  return result;
}

const config = { topic: "Algebra", half: 2 } satisfies Partial<Record<"topic" | "half", unknown>>;
const book = new Scorebook(config.topic, config.half as 1 | 2);
void book?.average?.();
typeof book === "object" && delete (config as { topic?: string }).topic;

export const reportText = `Report for ${config.topic}:
  Average ${book.average()?.toFixed(1) ?? "–"}
  Remark: ${`still open