import { Injectable, Inject } from "@acme/di";
import type { Slot } from './01-basics';

/**
 * Marks a method as logged.
 * @param area - Name of the log area
 * @returns A method decorator
 * @example
 *   @logged("releases")
 *   async load() {}
 */
function logged(area: string) {
  return function <T extends (...args: any[]) => any>(target: T, context: ClassMethodDecoratorContext): T {
    return function (this: unknown, ...args: Parameters<T>): ReturnType<T> {
      console.debug(`[${area}] ${String(context.name)}(${args.length} arguments)`); // [!code highlight]
      return target.apply(this, args);
    } as T;
  };
}

export abstract class Source<T> {
  protected abstract readonly url: string;
  abstract load(): Promise<T[]>;
}

// [!code focus]
@Injectable({ scope: "singleton" })
export class ReleaseService extends Source<Slot> implements Disposable {
  protected readonly url = "/api/v2/releases";
  #cache = new Map<string, Slot[]>();
  private static instances = 0;
  declare readonly accountId: string;

  constructor(@Inject("fetch") private readonly fetcher: typeof fetch = globalThis.fetch) {
    super();
    ReleaseService.instances++;
  }

  @logged("releases")
  async load(team = "all"): Promise<Slot[]> {
    const hit = this.#cache.get(team);
    if (hit) return hit;
    const response = await this.fetcher(`${this.url}?team=${team}`); // [!code --]
    const response = await this.fetcher(`${this.url}?team=${encodeURIComponent(team)}`, { cache: "no-store" }); // [!code ++]
    if (!response.ok) throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    const data = (await response.json()) as Slot[];
    this.#cache.set(team, data);
    return data;
  }

  // [!code word:Release]
  get releaseCount(): number {
    let sum = 0;
    for (const [, list] of this.#cache) sum += list.length;
    return sum;
  }

  // [!code highlight:3]
  [Symbol.dispose](): void {
    this.#cache.clear();
  }
}

export function groupBy<K extends PropertyKey, V>(values: readonly V[], key: (v: V) => K): Record<K, V[]> {
  return values.reduce((acc, value) => {
    const k = key(value); // [!code word:key:2]
    (acc[k] ??= []).push(value);
    return acc;
  }, {} as Record<K, V[]>);
}

namespace Internal {
  export const version: string = "2.4.0";
}

declare module "@acme/di" {
  interface Registry {
    releases: ReleaseService;
  }
}

export { Internal };
