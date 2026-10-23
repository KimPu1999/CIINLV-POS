import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const sources = await Promise.all([
  readFile(new URL('../src/features/registration-wizards.tsx', import.meta.url), 'utf8'),
  readFile(new URL('../src/features/password-recovery.tsx', import.meta.url), 'utf8'),
  readFile(new URL('../src/features/email-verification.tsx', import.meta.url), 'utf8'),
  readFile(new URL('../src/features/businesses.tsx', import.meta.url), 'utf8'),
])

test('password entry screens use the eight-character minimum and shared helper text', () => {
  const source = sources.join('\n')

  assert.match(source, /minLength=\{8\}/)
  assert.match(source, /length < 8/)
  assert.match(source, /Password must contain at least 8 characters\./)
  assert.doesNotMatch(source, /minLength=\{12\}|length < 12|At least 12 characters|at least 12 characters/)
})
