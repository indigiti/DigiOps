import fs from 'node:fs';

const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
const ui=read('src/main.js');
const status=read('public/api/deploy-status.php');
const agent=read('agent/digiops-agent.php');
const client=read('app/php/src/Targets/RemoteAgentClient.php');
const releaseManager=read('app/php/src/Deploy/ReleaseManager.php');
const deploy=read('public/api/deploy.php');

const checks=[
  ['UI reconciles aborted deploys', ui.includes("aborted?'DEPLOY_RESPONSE_TIMEOUT'") && ui.includes("status.state==='deployed'")],
  ['UI follows running agent state', ui.includes("status.state==='running'") && ui.includes("status.state==='failed'") && ui.includes('attempt<=120')],
  ['UI does not hard-fail a known running deployment', ui.includes("lastState==='running'") && ui.includes('no second deploy was started')],
  ['Control plane requires authoritative modern-agent marker', status.includes('$agentStatusSupported') && status.includes("'source'=>'agent-current-wait'")],
  ['Control plane exposes remote running state', status.includes("'state'=>'running'") && status.includes("'source'=>'agent-progress'")],
  ['Local ReleaseManager persists deployment progress', releaseManager.includes("deployment.json") && releaseManager.includes("'snapshotting'") && releaseManager.includes("'publishing'") && releaseManager.includes("'switching'")],
  ['Control plane exposes local running and failed state', status.includes("'source'=>'local-progress'") && status.includes("'source'=>'local-progress-wait'")],
  ['Agent survives client disconnects', agent.includes('@ignore_user_abort(true)') && agent.includes('@set_time_limit(600)')],
  ['Agent persists deployment progress', agent.includes("deployment.json") && agent.includes("setDeploymentState") && agent.includes("'snapshotting'") && agent.includes("'publishing'") && agent.includes("'switching'")],
  ['Agent status returns current and deployment records', agent.includes("ok(['current'=>$current,'deployment'=>$deployment])")],
  ['Agent blocks duplicate active deploys', agent.includes('DEPLOYMENT_ALREADY_RUNNING') && agent.includes('deploymentStateIsActive')],
  ['Remote commit timeout exceeds browser response window', client.includes("'deploy-commit'=>300")],
  ['Deployment status probes are fail-fast', client.includes("'deployment-status'=>5")],
  ['Browser confirmation recovery has a hard time budget', ui.includes('verificationWindowMs=420000') && ui.includes('apiTimed') && ui.includes('setTimeout(resolve,3000)')],
  ['Modern status failures are explicit, not legacy fallbacks', status.includes("'state'=>'unavailable'") && status.includes("'source'=>'agent-status-error'")],
  ['Deployments carry request identity end-to-end', ui.includes('deploymentRequestId') && ui.includes('requestId})') && deploy.includes("'requestId'=>$requestId") && status.includes('$deploymentRequestId') && releaseManager.includes("'requestId'") && agent.includes("'requestId'")],
  ['Same-commit retries cannot use registry-only confirmation', status.includes("$requestId==='' && $registryCommit===$commit")],
  ['Preflight requires request-aware deployment agent', deploy.includes("'deployment-request-id'") && agent.includes("'deployment-request-id'")],
  ['Preflight checks local deployment prerequisites', deploy.includes('PREFLIGHT_ZIP_EXTENSION_MISSING') && deploy.includes('PREFLIGHT_PRIVATE_STORAGE_NOT_WRITABLE') && deploy.includes('PREFLIGHT_DISK_SPACE_LOW')],
];

const failed=checks.filter(([,ok])=>!ok).map(([name])=>name);
if(failed.length){
  console.error('Deploy timeout/state-machine contract: FAIL');
  for(const item of failed) console.error(' - '+item);
  process.exit(1);
}
console.log('Deploy timeout/state-machine contract: PASS · '+checks.length+' checks');
