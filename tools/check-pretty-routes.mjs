import fs from 'node:fs'

const main=fs.readFileSync(new URL('../src/main.js',import.meta.url),'utf8')
const vite=fs.readFileSync(new URL('../vite.config.js',import.meta.url),'utf8')
const htaccess=fs.readFileSync(new URL('../public/.htaccess',import.meta.url),'utf8')

const required=[
  "base: '/digiops/'",
  "RewriteBase /digiops/",
  "RewriteRule ^api/ - [L]",
  "RewriteRule ^ index.html [L]",
  "routeFor(page,id='',tab='overview')",
  "window.addEventListener('popstate'",
  "apps/'+encodeURIComponent(id)"
]

const corpus=vite+'\n'+htaccess+'\n'+main
const missing=required.filter(token=>!corpus.includes(token))
if(missing.length){
  console.error('Pretty URL certification failed. Missing:',missing.join(', '))
  process.exit(1)
}

const forbiddenApi=[...main.matchAll(/api\(['"]([^'"]+)['"]/g)]
  .map(m=>m[1])
  .filter(url=>url.startsWith('../'))
if(forbiddenApi.length){
  console.error('Unsafe relative API paths:',forbiddenApi)
  process.exit(1)
}

console.log('Pretty URL routing certification: PASS')
