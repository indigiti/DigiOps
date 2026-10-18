(() => {
'use strict';
const $=id=>document.getElementById(id);
let csrf='';
const notice=(message)=>{$('notice').textContent=message};
async function request(path,options={}){
 const r=await fetch('/digiops/api/'+path,{credentials:'same-origin',cache:'no-store',...options});
 const j=await r.json();if(!r.ok)throw new Error(j.error||'Request denied');return j;
}
function showStatus(s){
 $('status').textContent='Staging mode: '+s.mode+' · data.gov.in key: '+(s.keyConfigured?'Saved':'Not configured')+
 ' · Admin encryption key: '+(s.authKeyConfigured?'Ready':'Not configured');
}
async function load(){
 try{
  const session=await request('session.php');
  if(!session.authenticated || session.user?.role!=='admin')throw new Error('Please log in to DigiOps as an administrator first.');
  csrf=session.csrf;
  const data=await request('krushiworld-setup.php');
  showStatus(data.status);
  $('setup').classList.remove('hide');
  notice('DigiOps administrator verified. All settings remain in private Cloudways storage.');
 }catch(e){notice(e.message)}
}
async function action(type){
 const button=type==='grant'?$('grant'):$('save');
 button.disabled=true;notice('Saving securely…');
 try {
  const payload={action:type};
  if(type==='configure')payload.api_key=$('apiKey').value.trim();
  const r=await request('krushiworld-setup.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(payload)});
  if(type==='configure'){
   $('apiKey').value='';showStatus(r.status);notice('Private settings saved. Secrets were not returned in the response.');
  }else{
   $('grantValue').textContent=r.grant;$('grantDetails').classList.remove('hide');
   notice('One-time setup code generated. Use it on KrushiPulse Admin within 15 minutes.');
  }
 }catch(e){notice('Could not complete: '+e.message)}
 finally{button.disabled=false}
}
$('save').addEventListener('click',()=>action('configure'));
$('grant').addEventListener('click',()=>action('grant'));
$('copy').addEventListener('click',async()=>{try{await navigator.clipboard.writeText($('grantValue').textContent);notice('Setup code copied; it will not be shown again after this page closes.')}catch{notice('Select and copy the displayed setup code manually.')}});
load();
})();
