import fs from 'node:fs';

const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
const ui=read('src/main.js');
const status=read('public/api/deploy-status.php');
const jobsApi=read('public/api/deployment-jobs.php');
const agent=read('agent/digiops-agent.php');
const client=read('app/php/src/Targets/RemoteAgentClient.php');
const releaseManager=read('app/php/src/Deploy/ReleaseManager.php');
const switcher=read('app/php/src/Deploy/AtomicReleaseSwitcher.php');
const jobRepo=read('app/php/src/Deploy/DeploymentJobRepository.php');
const deploy=read('public/api/deploy.php');
const health=read('public/api/health.php');
const githubClient=read('app/php/src/GitHub/GitHubClient.php');

const checks=[
  ['UI hands aborted deploys to background verification', ui.includes("aborted?'DEPLOY_RESPONSE_TIMEOUT'") && ui.includes('queueDeploymentWatch')],
  ['UI reloads deployment watches from server journal', ui.includes("api('./api/deployment-jobs.php')") && ui.includes('syncDeploymentWatches') && !ui.includes('DEPLOYMENT_WATCH_KEY')],
  ['UI follows authoritative watcher state', ui.includes("watch.status==='deployed'") && ui.includes("watch.status==='failed'") && ui.includes("watch.status=status&&status.state")],
  ['UI prevents duplicate deploy while verification is active', ui.includes('selectedDeploymentWatch') && ui.includes('Verification running')],
  ['Control plane exposes server-side deployment jobs', jobsApi.includes('DeploymentJobRepository') && jobRepo.includes("jobs/deployments") && jobRepo.includes('latestForProject')],
  ['Deployment jobs retain a verification ledger', jobRepo.includes("'verification'=>[]") && jobRepo.includes('updateVerification') && ui.includes('Verification Center') && ui.includes('verificationSteps')],
  ['Deploy endpoint creates durable job before long work', deploy.includes("$jobs->create") && deploy.includes("'phase'=>'candidate-validation'")],
  ['Deploy status persists authoritative state into job journal', status.includes('DeploymentJobRepository') && status.includes("$jobs->patch")],
  ['Post-deploy health is attached to deployment job', health.includes('DeploymentJobRepository') && health.includes("'health-verified'") && health.includes("'health-attention'")],
  ['Detailed health state persists across reloads', health.includes("'healthDetail'=>$result") && ui.includes('selectedHealthUrl') && ui.includes('selectedHealthCheckedAt')],
  ['Health UI exposes an explicit fresh check action', ui.includes("@click=\"checkHealth(false)\"") && ui.includes('Resolved health URL')],
  ['Direct deploy health keeps request identity', ui.includes('checkHealth(true,requestId)') && ui.includes('healthPayload.requestId=requestId')],
  ['Only uncertain remote commit responses enter authoritative confirmation', deploy.includes("str_starts_with($message,'REMOTE_COMMIT_UNCERTAIN_')") && ui.includes("payload.state==='unavailable'")],
  ['Remote agent independently enforces Cloudways roots', agent.includes('safeManagedRelative') && agent.includes("'public_html'") && agent.includes("'private_html'")],
  ['DigiOps private release cannot overwrite persistent state', releaseManager.includes('validatePrivatePayload') && releaseManager.includes("['app','agent','build']") && agent.includes('validatePrivatePayload')],
  ['Control plane requires authoritative modern-agent marker', status.includes('$agentStatusSupported') && status.includes("'source'=>'agent-current-wait'")],
  ['Control plane exposes remote running state', status.includes("'state'=>'running'") && status.includes("'source'=>'agent-progress'")],
  ['Local ReleaseManager persists deployment progress', releaseManager.includes("deployment.json") && releaseManager.includes("'snapshotting'") && releaseManager.includes("'publishing'") && releaseManager.includes("'switching'")],
  ['Transactional switcher parks current release before cutover', switcher.includes('CURRENT_RELEASE_PARK_FAILED') && switcher.includes('PUBLISH_RENAME_FAILED_RESTORED')],
  ['Local deploy and rollback share transactional switcher', releaseManager.match(/switcher->switch/g)?.length>=2],
  ['Remote agent uses transactional switcher', agent.includes('atomicSwitchDir') && agent.includes("'atomic-switch-v1'")],
  ['Remote preflight requires transactional agent capability', deploy.includes("'atomic-switch-v1'")],
  ['Agent survives client disconnects', agent.includes('@ignore_user_abort(true)') && agent.includes('@set_time_limit(600)')],
  ['Agent blocks duplicate active deploys', agent.includes('DEPLOYMENT_ALREADY_RUNNING') && agent.includes('deploymentStateIsActive')],
  ['Remote commit timeout exceeds browser response window', client.includes("'deploy-commit'=>300")],
  ['Deployment status probes are fail-fast', client.includes("'deployment-status'=>5")],
  ['Modern status failures are explicit, not legacy fallbacks', status.includes("'state'=>'unavailable'") && status.includes("'source'=>'agent-status-error'")],
  ['Deployments carry request identity end-to-end', ui.includes('deploymentRequestId') && deploy.includes("'requestId'=>$requestId") && status.includes('$deploymentRequestId') && releaseManager.includes("'requestId'") && agent.includes("'requestId'")],
  ['Same-commit retries cannot use registry-only confirmation', status.includes("$requestId==='' && $registryCommit===$commit")],
  ['Preflight checks local deployment prerequisites', deploy.includes('PREFLIGHT_ZIP_EXTENSION_MISSING') && deploy.includes('PREFLIGHT_PRIVATE_STORAGE_NOT_WRITABLE') && deploy.includes('PREFLIGHT_DISK_SPACE_LOW')],
  ['Artifact digest is verified when GitHub provides SHA-256', deploy.includes('ARTIFACT_DIGEST_MISMATCH') && deploy.includes("str_starts_with($artifactDigest,'sha256:')")],
  ['Large artifact downloads use resilient timeout and retries', githubClient.includes('ARTIFACT_TRANSFER_TIMEOUT = 600') && githubClient.includes('ARTIFACT_ATTEMPTS = 3') && githubClient.includes('ARTIFACT_BLOB_INCOMPLETE')],
  ['Artifact downloads promote only after validation', githubClient.includes("$target.'.part-'") && githubClient.includes('assertZipFile($part)') && githubClient.includes('hash_equals($expected,$actual)') && githubClient.includes('rename($part,$target)')],
  ['Deploy execution window covers artifact retry budget', deploy.includes('@set_time_limit(2100)')],
];

const failed=checks.filter(([,ok])=>!ok).map(([name])=>name);
if(failed.length){
  console.error('Deploy reliability contract: FAIL');
  for(const item of failed) console.error(' - '+item);
  process.exit(1);
}
console.log('Deploy reliability contract: PASS · '+checks.length+' checks');
