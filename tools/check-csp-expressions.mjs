import fs from 'node:fs'

const source = fs.readFileSync(new URL('../src/main.js', import.meta.url), 'utf8')
const binding = /(?:x-[\w:-]+|@[\w.:-]+|:[\w:-]+)="([^"]*)"/g
const forbidden = [
  {token:'?.', label:'optional chaining'},
  {token:'??', label:'nullish coalescing'},
]

const failures = []
for (const match of source.matchAll(binding)) {
  const full = match[0]
  const expression = match[1]
  for (const rule of forbidden) {
    if (expression.includes(rule.token)) {
      failures.push({rule:rule.label, expression, attribute:full})
    }
  }
}

if (failures.length) {
  console.error('CSP Alpine expression compatibility check failed:')
  for (const failure of failures) {
    console.error('- ' + failure.rule + ': ' + failure.attribute)
  }
  process.exit(1)
}

console.log('CSP Alpine expression compatibility: PASS')
