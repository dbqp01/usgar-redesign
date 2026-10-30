import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

describe('public runtime access', () => {
  it('blocks access to rate-limit files while leaving public assets alone', () => {
    const htaccess = readFileSync('public/.htaccess', 'utf8');
    const rule = htaccess.match(/^RewriteRule\s+(\S+)\s+-\s+\[F,L,NC\]$/m);
    expect(rule).not.toBeNull();
    const pattern = new RegExp(rule![1], 'i');
    expect(pattern.test('data/limits/limit_example.json')).toBe(true);
    expect(pattern.test('DATA/limits/limit_example.json')).toBe(true);
    expect(pattern.test('assets/rooms/example.jpg')).toBe(false);
  });
});
