import Alpine from '@alpinejs/csp'
import { createIcons, Activity, AppWindow, ArrowLeft, Boxes, CheckCircle2, ChevronDown, CircleGauge, Cloud, FileClock, FileText, FolderGit2, GitBranch, Github, Globe2, HardDrive, HeartPulse, History, LayoutDashboard, ListFilter, LockKeyhole, LogOut, Menu, MoreVertical, PackageCheck, Plus, RefreshCw, Rocket, Search, Server, Settings2, ShieldCheck, UserRound, X, Zap } from 'lucide'
import './styles.css'

window.Alpine = Alpine
const ICONS={Activity,AppWindow,ArrowLeft,Boxes,CheckCircle2,ChevronDown,CircleGauge,Cloud,FileClock,FileText,FolderGit2,GitBranch,Github,Globe2,HardDrive,HeartPulse,History,LayoutDashboard,ListFilter,LockKeyhole,LogOut,Menu,MoreVertical,PackageCheck,Plus,RefreshCw,Rocket,Search,Server,Settings2,ShieldCheck,UserRound,X,Zap}
const icons=()=>queueMicrotask(()=>createIcons({icons:ICONS}))
const APP_BASE=(import.meta.env.BASE_URL||'/digiops/').replace(/\/+$/,'')+'/'
const appUrl=(path='')=>APP_BASE+String(path||'').replace(/^\/+/,'')

const renderNativePathPreview=()=>{
  const slugInput=document.getElementById('digiops-app-slug')
  const publicEl=document.getElementById('digiops-public-path-preview')
  const privateEl=document.getElementById('digiops-private-path-preview')
  if(!slugInput || !publicEl || !privateEl) return
  const slug=String(slugInput.value||'').toLowerCase().trim().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'')
  publicEl.textContent='public_html/'+(slug||'{slug}')+'/'
  privateEl.textContent='private_html/'+(slug||'{slug}')+'/'
}

const api=async(url,options={})=>{
  const target=String(url||'').startsWith('./')?appUrl(String(url).slice(2)):url
  const res=await fetch(target,{credentials:'same-origin',cache:'no-store',...options})
  const data=await res.json().catch(()=>({error:'INVALID_RESPONSE'}))
  if(!res.ok) throw new Error(data.error||('HTTP_'+res.status))
  return data
}

function app(){
  return {
    ready:false, installed:false, user:null, csrf:null, authMode:'login',
    sidebarOpen:false, page:'dashboard', projectTab:'overview', query:'', filter:'all', routeReady:false,
    projects:[], targets:[], selectedId:null, releases:[], githubInfo:null, fileListing:null, health:null, audit:[],
    detailCache:{github:{},releases:{},health:{},files:{}}, requestPool:{},
    modal:null, busy:false, notice:'', error:'', operationTimer:null,
    operation:{active:false,type:'',title:'',message:'',percent:0,status:'idle',estimated:false},
    login:{username:'',password:'',totp:''},
    install:{name:'Administrator',username:'admin',password:'',confirm:'',totpSecret:''},
    github:{token:'',testRepo:'indigiti/DigiOps'},
    infra:null, runtimeInfo:null, redisStatus:null,
    redisMode:'cloudways',
    redisAdvanced:false,
    redisForm:{host:'127.0.0.1',port:6379,username:'',password:'',database:0,prefix:'digiops:',timeout:1.5},
    varnishForm:{enabled:true,bypassPath:'/digiops/',sessionCookie:'DIGIOPSSESSID',apiPath:'/digiops/api/'},
    targetForm:{id:'',name:'',type:'agent',endpoint:'',secret:'',publicBase:'public_html',privateBase:'private_html'},
    form:{name:'',repo:'',branch:'main',slug:'',artifactName:'digiops-release',healthPath:'/',retention:5,targetId:'local',url:'',publicPath:'',privatePath:''},

    async init(){
      await this.bootstrap()
      window.addEventListener('popstate',()=>this.applyRoute(window.location.pathname,false))
      if(this.user) await this.applyRoute(window.location.pathname,true)
      this.routeReady=true
      icons()
    },
    async bootstrap(){
      this.clearMessages()
      try{
        const s=await api('./api/session.php')
        this.installed=s.installed
        this.user=s.user
        this.csrf=s.csrf
        if(this.user){await Promise.all([this.loadProjects(),this.loadTargets(),this.loadRuntimeInfo()])}
      }catch(e){this.error=e.message}
      finally{this.ready=true;icons()}
    },
    clearMessages(){this.notice='';this.error=''},
    cacheGet(bucket,key,ttlMs){
      const group=this.detailCache[bucket]||{}
      const entry=group[key]
      if(!entry)return null
      if(ttlMs>0 && (Date.now()-entry.time)>ttlMs)return null
      return entry.value
    },
    cachePut(bucket,key,value){
      if(!this.detailCache[bucket])this.detailCache[bucket]={}
      this.detailCache[bucket][key]={time:Date.now(),value}
      return value
    },
    cacheDropProject(id){
      for(const bucket of ['github','releases','health']){
        if(this.detailCache[bucket])delete this.detailCache[bucket][id]
      }
      if(this.detailCache.files){
        for(const key of Object.keys(this.detailCache.files)){
          if(key.startsWith(id+'|'))delete this.detailCache.files[key]
        }
      }
    },
    async singleFlight(key,loader){
      if(this.requestPool[key])return this.requestPool[key]
      const promise=Promise.resolve().then(loader)
      this.requestPool[key]=promise
      try{return await promise}
      finally{delete this.requestPool[key]}
    },
    async doInstall(){
      this.clearMessages()
      if(this.install.password!==this.install.confirm){this.error='PASSWORD_CONFIRM_MISMATCH';return}
      this.busy=true
      try{
        const d=await api('./api/install.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(this.install)})
        this.installed=true;this.user=d.user;this.csrf=d.csrf;this.notice='DigiOps installed successfully.'
        await this.loadProjects();await this.loadTargets()
      }catch(e){this.error=e.message}
      finally{this.busy=false;icons()}
    },
    async doLogin(){
      this.clearMessages();this.busy=true
      try{
        const d=await api('./api/login.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(this.login)})
        this.user=d.user;this.csrf=d.csrf;this.login.password='';this.notice='Signed in.'
        await Promise.all([this.loadProjects(),this.loadTargets(),this.loadRuntimeInfo()]);await this.applyRoute(window.location.pathname,true)
      }catch(e){this.error=e.message}
      finally{this.busy=false;icons()}
    },
    async logout(){
      this.clearMessages()
      try{await api('./api/logout.php',{method:'POST',headers:{'X-CSRF-Token':this.csrf}})}catch{}
      this.user=null;this.csrf=null;this.projects=[];this.page='dashboard';history.replaceState({},'',APP_BASE);icons()
    },
    async loadProjects(){
      const d=await api('./api/projects.php');this.projects=d.projects||[];icons()
    },
    async loadTargets(){
      try{
        const d=await api('./api/targets.php')
        this.targets=d.targets||[]
      }catch(e){this.error=e.message}
      icons()
    },
    get stats(){
      return {
        total:this.projects.length,
        updates:this.projects.filter(p=>p.update).length,
        healthy:this.projects.filter(p=>p.health==='healthy').length,
        attention:this.projects.filter(p=>p.health==='attention').length,
        pending:this.projects.filter(p=>!p.health||p.health==='pending').length,
        targets:this.targets.length,
        remoteTargets:this.targets.filter(t=>t.id!=='local').length
      }
    },
    get attentionProjects(){
      return this.projects
        .filter(p=>p.health==='attention'||p.update)
        .sort((a,b)=>(Number(b.health==='attention')-Number(a.health==='attention'))||(Number(b.update)-Number(a.update)))
        .slice(0,8)
    },
    get targetSummaries(){
      return this.targets.map(t=>{
        const apps=this.projects.filter(p=>(p.targetId||'local')===t.id)
        return {
          id:t.id,name:t.name,status:t.status||'unverified',apps:apps.length,
          healthy:apps.filter(p=>p.health==='healthy').length,
          attention:apps.filter(p=>p.health==='attention').length
        }
      }).sort((a,b)=>b.apps-a.apps)
    },
    get recentDeployments(){
      return this.projects
        .filter(p=>p.lastDeploy&&p.lastDeploy!=='Never')
        .slice()
        .sort((a,b)=>new Date(b.lastDeploy)-new Date(a.lastDeploy))
        .slice(0,6)
    },
    get filteredProjects(){
      const q=this.query.trim().toLowerCase()
      return this.projects.filter(p=>{
        const m=!q||[p.name,p.repo,p.url,p.branch].some(v=>String(v||'').toLowerCase().includes(q))
        const f=this.filter==='all'||(this.filter==='updates'&&p.update)||(this.filter==='healthy'&&p.health==='healthy')||(this.filter==='attention'&&p.health==='attention')
        return m&&f
      })
    },
    get selected(){return this.projects.find(p=>p.id===this.selectedId)||null},
    get userName(){return this.user && this.user.name ? this.user.name : ''},
    get userRole(){return this.user && this.user.role ? this.user.role : ''},
    get selectedName(){return this.selected ? this.selected.name : ''},
    get selectedRepo(){return this.selected ? this.selected.repo : ''},
    get selectedBranch(){return this.selected ? this.selected.branch : ''},
    get selectedUrl(){return this.selected ? this.selected.url : ''},
    get selectedPublicPath(){return this.selected ? this.selected.publicPath : ''},
    get selectedPrivatePath(){return this.selected ? this.selected.privatePath : ''},
    get selectedCommit(){return this.selected ? this.selected.commit : ''},
    get selectedArtifactName(){return this.selected ? this.selected.artifactName : ''},
    get selectedRetention(){return this.selected ? this.selected.retention : ''},
    get selectedHealthPath(){return this.selected ? this.selected.healthPath : ''},
    get selectedTargetId(){return this.selected && this.selected.targetId ? this.selected.targetId : 'local'},
    get runtimeVersion(){return this.runtimeInfo&&this.runtimeInfo.version?this.runtimeInfo.version:'—'},
    get runtimeArtifactId(){return this.runtimeInfo&&this.runtimeInfo.artifactId?String(this.runtimeInfo.artifactId):'—'},
    get runtimeSourceShort(){return this.runtimeInfo&&this.runtimeInfo.sourceSha?String(this.runtimeInfo.sourceSha).slice(0,12):'—'},
    get runtimeSourceFull(){return this.runtimeInfo&&this.runtimeInfo.sourceSha?String(this.runtimeInfo.sourceSha):''},
    targetName(id){const t=this.targets.find(x=>x.id===id);return t?t.name:id},
    async copyText(value){
      if(!value)return
      try{await navigator.clipboard.writeText(String(value));this.notice='Copied to clipboard.'}
      catch{this.error='COPY_FAILED'}
    },
    get githubConnected(){return !!(this.githubInfo && this.githubInfo.connected===true)},
    get githubNeedsConnection(){return !!(this.githubInfo && this.githubInfo.connected===false)},
    get githubError(){return this.githubInfo && this.githubInfo.error ? this.githubInfo.error : ''},
    get candidate(){return this.githubInfo && this.githubInfo.candidate ? this.githubInfo.candidate : null},
    get candidateReady(){return !!(this.candidate && this.candidate.ready)},
    get candidateReason(){return this.candidate && this.candidate.reason ? this.candidate.reason : 'checking'},
    get candidateRun(){return this.candidate && this.candidate.run ? this.candidate.run : null},
    get candidateArtifact(){return this.candidate && this.candidate.artifact ? this.candidate.artifact : null},
    get candidateCommit(){return this.candidate && this.candidate.commit ? this.candidate.commit : null},
    get branchHead(){return this.githubInfo && this.githubInfo.branchHead ? this.githubInfo.branchHead : null},
    get deployedInfo(){return this.githubInfo && this.githubInfo.deployed ? this.githubInfo.deployed : null},
    get candidateRunNumber(){return this.candidateRun && this.candidateRun.number ? '#'+this.candidateRun.number : '—'},
    get candidateRunId(){return this.candidateRun && this.candidateRun.id ? String(this.candidateRun.id) : '—'},
    get candidateArtifactId(){return this.candidateArtifact && this.candidateArtifact.id ? String(this.candidateArtifact.id) : '—'},
    get candidateArtifactName(){return this.candidateArtifact && this.candidateArtifact.name ? this.candidateArtifact.name : this.selectedArtifactName},
    get candidateArtifactSize(){return this.candidateArtifact && this.candidateArtifact.sizeBytes ? this.formatBytes(this.candidateArtifact.sizeBytes) : '—'},
    get candidateArtifactExpires(){return this.candidateArtifact && this.candidateArtifact.expiresAt ? this.candidateArtifact.expiresAt : '—'},
    get candidateCommitSha(){return this.candidateCommit && this.candidateCommit.sha ? this.candidateCommit.sha : '—'},
    get candidateCommitShort(){return this.candidateCommitSha==='—'?'—':this.candidateCommitSha.slice(0,12)},
    get candidateCommitMessage(){return this.candidateCommit && this.candidateCommit.message ? this.candidateCommit.message : '—'},
    get candidateCommitDate(){return this.candidateCommit && this.candidateCommit.date ? this.candidateCommit.date : '—'},
    get candidateCommitAuthor(){return this.candidateCommit && this.candidateCommit.author ? this.candidateCommit.author : '—'},
    get branchHeadShort(){return this.branchHead && this.branchHead.sha ? this.branchHead.sha.slice(0,12) : '—'},
    get deployedCommitShort(){return this.deployedInfo && this.deployedInfo.commit ? this.deployedInfo.commit.slice(0,12) : (this.selectedCommit && this.selectedCommit!=='—'?this.selectedCommit.slice(0,12):'—')},
    get deployedRelease(){return this.deployedInfo && this.deployedInfo.release ? this.deployedInfo.release : (this.selected ? this.selected.release : 'Not deployed')},
    get deployedLastDeploy(){return this.deployedInfo && this.deployedInfo.lastDeploy ? this.deployedInfo.lastDeploy : (this.selected ? this.selected.lastDeploy : 'Never')},
    get deployableStatusText(){
      if(!this.githubInfo)return 'Not checked yet — use Check update when you need fresh deployment data.'
      if(!this.githubConnected)return 'GitHub not connected'
      if(!this.candidateReady)return 'No deployable artifact: '+this.candidateReason
      if(this.candidate.alreadyDeployed)return 'Latest successful artifact is already deployed'
      if(this.candidate.branchAhead)return 'Deployable build ready; branch HEAD has newer unbuilt or unsuccessful changes'
      return 'Verified deployment candidate ready'
    },
    get checkingUpdate(){return this.operation.active && this.operation.type==='update'},
    get deploying(){return this.operation.active && this.operation.type==='deploy'},
    get rollingBack(){return this.operation.active && this.operation.type==='rollback'},
    get checkingHealth(){return this.operation.active && this.operation.type==='health'},
    get operationWidth(){return 'width:'+Math.max(0,Math.min(100,Number(this.operation.percent)||0))+'%'},
    get operationPercentLabel(){return Math.round(Number(this.operation.percent)||0)+'%'},
    get operationTone(){return this.operation.status==='error'?'operation-error':this.operation.status==='success'?'operation-success':'operation-running'},
    get operationStatusLabel(){return this.operation.status==='error'?'Failed':this.operation.status==='success'?'Completed':this.operation.estimated?'Estimated progress':'In progress'},
    get noticeTone(){return /attention|warning|needs/i.test(this.notice)?'notice-warning':'notice-success'},
    formatBytes(bytes){
      const n=Number(bytes)||0
      if(n<1024)return n+' B'
      if(n<1048576)return (n/1024).toFixed(1)+' KB'
      if(n<1073741824)return (n/1048576).toFixed(1)+' MB'
      return (n/1073741824).toFixed(2)+' GB'
    },
    formatDate(value){
      if(!value)return '—'
      const d=new Date(value)
      if(Number.isNaN(d.getTime()))return String(value)
      return new Intl.DateTimeFormat('en-IN',{dateStyle:'medium',timeStyle:'short'}).format(d)
    },
    releaseCommitShort(r){
      const value=r && r.commit ? String(r.commit) : ''
      return value && value!=='snapshot' ? value.slice(0,12) : 'snapshot'
    },
    releaseType(r){
      if(r && r.type)return String(r.type)
      return r && String(r.id||'').startsWith('pre-') ? 'snapshot' : 'release'
    },
    releaseCreated(r){return this.formatDate(r && r.createdAt ? r.createdAt : '')},
    releaseSize(r){return this.formatBytes(r && r.size ? r.size : 0)},
    isCurrentRelease(r){return !!(r && this.selected && String(r.id||'')===String(this.selected.release||''))},
    startOperation(type,title,message,percent=5,estimated=false){
      this.stopOperationTimer()
      this.operation={active:true,type,title,message,percent,status:'running',estimated}
    },
    setOperation(percent,message){
      this.operation.percent=Math.max(Number(this.operation.percent)||0,Number(percent)||0)
      if(message)this.operation.message=message
    },
    runEstimatedStages(stages,interval=1500){
      let index=0
      this.operation.estimated=true
      this.operationTimer=setInterval(()=>{
        if(!this.operation.active || this.operation.status!=='running'){this.stopOperationTimer();return}
        if(index>=stages.length){this.stopOperationTimer();return}
        const stage=stages[index++]
        this.setOperation(stage.percent,stage.message)
      },interval)
    },
    stopOperationTimer(){
      if(this.operationTimer){clearInterval(this.operationTimer);this.operationTimer=null}
    },
    completeOperation(message){
      this.stopOperationTimer()
      this.operation.percent=100
      this.operation.message=message||'Completed.'
      this.operation.status='success'
      this.operation.estimated=false
      setTimeout(()=>{if(this.operation.status==='success')this.operation.active=false},1200)
    },
    failOperation(message){
      this.stopOperationTimer()
      this.operation.message=message||'Operation failed.'
      this.operation.status='error'
      this.operation.estimated=false
      setTimeout(()=>{if(this.operation.status==='error')this.operation.active=false},2800)
    },
    get latestWorkflowStatus(){
      if(!this.githubInfo || !Array.isArray(this.githubInfo.runs) || !this.githubInfo.runs.length)return 'None'
      const run=this.githubInfo.runs[0]
      return run.conclusion || run.status || 'None'
    },
    get updateMessage(){
      if(!this.githubInfo)return 'Not checked yet. No GitHub request is made when the application opens.'
      if(this.githubNeedsConnection)return 'Connect GitHub to check repository updates.'
      return this.githubInfo.updateAvailable ? 'New commit available.' : 'No newer commit detected.'
    },
    get filePathLabel(){
      if(!this.fileListing)return ''
      return (this.fileListing.scope||'') + ':/' + (this.fileListing.path||'')
    },
    get fileItems(){return this.fileListing && Array.isArray(this.fileListing.items) ? this.fileListing.items : []},
    get healthHttpText(){
      return this.health && this.health.http && this.health.http.status ? 'HTTP '+this.health.http.status+' · '+this.health.http.ms+'ms' : 'Not checked'
    },
    get healthStorageText(){
      return this.health && this.health.storage && this.health.storage.exists ? this.health.storage.bytes+' bytes' : 'Not deployed'
    },
    get healthRuntimeText(){
      return this.health && this.health.runtime && this.health.runtime.php ? 'PHP '+this.health.runtime.php : 'Not checked'
    },
    auditHashPrefix(a){return a && a.hash ? String(a.hash).slice(0,12) : ''},
    appUrl(path=''){return appUrl(path)},
    routePath(){
      const raw=window.location.pathname
      const base=APP_BASE.replace(/\/$/,'')
      if(raw===base||raw===base+'/')return ''
      if(raw.startsWith(base+'/'))return raw.slice(base.length+1)
      return ''
    },
    routeFor(page,id='',tab='overview'){
      if(page==='dashboard')return ''
      if(page==='projects')return 'apps'
      if(page==='targets')return 'targets'
      if(page==='audit')return 'audit'
      if(page==='settings')return 'settings'
      if(page==='project'&&id){
        return 'apps/'+encodeURIComponent(id)+(tab&&tab!=='overview'?'/'+encodeURIComponent(tab):'')
      }
      return ''
    },
    async navigateRoute(path,replace=false){
      const url=appUrl(path)
      if(replace)history.replaceState({},'',url)
      else if(window.location.pathname!==new URL(url,window.location.origin).pathname)history.pushState({},'',url)
      await this.applyRoute(new URL(url,window.location.origin).pathname,false)
    },
    async applyRoute(pathname,replaceInvalid=false){
      const base=APP_BASE.replace(/\/$/,'')
      let relative=''
      if(pathname===base||pathname===base+'/')relative=''
      else if(pathname.startsWith(base+'/'))relative=pathname.slice(base.length+1)
      else {
        if(replaceInvalid)history.replaceState({},'',APP_BASE)
        relative=''
      }
      const parts=relative.split('/').filter(Boolean).map(v=>decodeURIComponent(v))
      this.clearMessages()
      this.sidebarOpen=false
      if(parts.length===0){
        this.page='dashboard';this.selectedId=null;this.projectTab='overview';icons();return
      }
      if(parts[0]==='apps'&&parts.length===1){
        this.page='projects';this.selectedId=null;this.projectTab='overview';icons();return
      }
      if(parts[0]==='apps'&&parts[1]){
        const project=this.projects.find(p=>p.id===parts[1])
        const allowed=['overview','deploy','releases','files','health','settings']
        const tab=parts[2]&&allowed.includes(parts[2])?parts[2]:'overview'
        if(!project){
          this.page='projects';this.selectedId=null;this.projectTab='overview'
          if(replaceInvalid)history.replaceState({},'',appUrl('apps'))
          icons();return
        }
        this.selectedId=project.id;this.page='project';this.projectTab=tab
        this.githubInfo=this.cacheGet('github',project.id,120000)
        this.releases=this.cacheGet('releases',project.id,300000)||[]
        this.health=this.cacheGet('health',project.id,60000)
        this.fileListing=null
        if(tab==='releases'&&this.releases.length===0)this.loadReleases(false)
        if(tab==='files'&&!this.fileListing)this.browse('public','',false)
        icons();return
      }
      if(parts[0]==='targets'&&this.userRole==='admin'){this.page='targets';this.selectedId=null;await this.loadTargets();icons();return}
      if(parts[0]==='audit'&&this.userRole==='admin'){this.page='audit';this.selectedId=null;await this.loadAudit();icons();return}
      if(parts[0]==='settings'){this.page='settings';this.selectedId=null;await Promise.allSettled([this.loadInfrastructure(),this.loadRuntimeInfo()]);icons();return}
      this.page='dashboard';this.selectedId=null;this.projectTab='overview'
      if(replaceInvalid)history.replaceState({},'',APP_BASE)
      icons()
    },
    async go(page){
      await this.navigateRoute(this.routeFor(page))
    },
    async openProject(id){
      await this.navigateRoute(this.routeFor('project',id,'overview'))
    },
    normalizeSlug(value){
      return String(value||'').toLowerCase().trim().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'')
    },
    updatePathPreview(){
      renderNativePathPreview()
    },
    slugify(){
      this.form.slug=this.normalizeSlug(this.form.name)
      queueMicrotask(()=>this.updatePathPreview())
    },
    syncSlug(value){
      this.form.slug=this.normalizeSlug(value)
      queueMicrotask(()=>this.updatePathPreview())
    },
    openCreate(){
      Object.assign(this.form,{name:'',repo:'',branch:'main',slug:'',artifactName:'digiops-release',healthPath:'/',retention:5,targetId:'local',url:'',publicPath:'',privatePath:''})
      this.modal='create'
      queueMicrotask(()=>this.updatePathPreview())
      icons()
    },
    async saveProject(){
      this.clearMessages()
      this.form.slug=this.normalizeSlug(this.form.slug)
      this.updatePathPreview()
      if(!this.form.slug){this.error='INVALID_APP_SLUG';return}
      this.busy=true
      try{
        const payload={
          id:this.form.slug,
          name:this.form.name.trim(),
          repo:this.form.repo.trim(),
          branch:this.form.branch.trim(),
          artifactName:this.form.artifactName.trim()||'digiops-release',
          healthPath:this.form.healthPath.trim()||'/',
          retention:Number(this.form.retention)||5,
          targetId:this.form.targetId||'local'
        }
        if(payload.targetId!=='local'){
          payload.url=this.form.url.trim()
          payload.publicPath=this.form.publicPath.trim()||('public_html/'+this.form.slug+'/')
          payload.privatePath=this.form.privatePath.trim()||('private_html/'+this.form.slug+'/')
        }
        await api('./api/projects.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':this.csrf},body:JSON.stringify(payload)})
        this.modal=null;await this.loadProjects();this.notice='Application registered.'
      }catch(e){this.error=e.message}
      finally{this.busy=false;icons()}
    },
    async connectGithub(){
      this.clearMessages();this.busy=true
      try{
        await api('./api/github.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':this.csrf},body:JSON.stringify(this.github)})
        this.github.token='';this.notice='GitHub connected.';if(this.selected)await this.loadGithubInfo()
      }catch(e){this.error=e.message}
      finally{this.busy=false;icons()}
    },
    async checkUpdate(){
      if(!this.selected)return
      this.clearMessages();this.busy=true
      this.startOperation('update','Checking for updates','Connecting to GitHub and reading branch state…',12,false)
      try{
        this.setOperation(28,'Reading workflow runs and artifacts…')
        const ok=await this.loadGithubInfo(true)
        if(!ok)throw new Error(this.githubError||'UPDATE_CHECK_FAILED')
        this.setOperation(82,'Comparing deployed commit with the latest successful artifact…')
        this.setOperation(96,'Refreshing deployment candidate details…')
        this.notice=this.githubInfo && this.githubInfo.updateAvailable ? 'Update available and deployment candidate refreshed.' : 'Application is already on the latest deployable build.'
        this.completeOperation('Update check complete.')
      }catch(e){
        this.error=e.message;this.failOperation('Update check failed: '+e.message)
      }finally{this.busy=false;icons()}
    },
    async loadGithubInfo(force=false){
      if(!this.selected)return false
      const id=this.selected.id
      const cached=!force?this.cacheGet('github',id,120000):null
      if(cached){
        this.githubInfo=cached
        icons()
        return true
      }
      try{
        const data=await this.singleFlight('github:'+id,()=>api('./api/github.php?project='+encodeURIComponent(id)))
        this.githubInfo=this.cachePut('github',id,data)
        await this.loadProjects()
        icons()
        return true
      }catch(e){
        this.githubInfo={error:e.message}
        icons()
        return false
      }
    },
    async loadReleases(force=false){
      if(!this.selected)return
      const id=this.selected.id
      const cached=!force?this.cacheGet('releases',id,300000):null
      if(cached){
        this.releases=cached
        icons()
        return
      }
      try{
        const d=await this.singleFlight('releases:'+id,()=>api('./api/releases.php?project='+encodeURIComponent(id)))
        this.releases=this.cachePut('releases',id,d.releases||[])
      }catch(e){this.error=e.message}
      icons()
    },
    async deploy(){
      if(!this.selected)return
      if(!this.githubInfo) await this.loadGithubInfo()
      if(!this.githubConnected){
        this.error='GitHub connection required. Open Connections and save a GitHub token before deployment.'
        return
      }
      if(!this.candidateReady){
        this.error='No verified deployment candidate is ready. Run Check update first.'
        return
      }
      const summary='Deploy '+this.candidateRunNumber+' · artifact '+this.candidateArtifactId+' · '+this.candidateCommitShort+' to '+this.selected.url+'?'
      if(!confirm(summary))return
      this.clearMessages();this.busy=true
      this.startOperation('deploy','Deploying '+this.selectedName,'Preparing verified deployment candidate…',6,true)
      this.runEstimatedStages([
        {percent:14,message:'Locking deployment target…'},
        {percent:28,message:'Downloading the selected GitHub artifact…'},
        {percent:44,message:'Validating ZIP paths, payload and entrypoint…'},
        {percent:60,message:'Creating a pre-deploy snapshot…'},
        {percent:74,message:'Publishing the public release…'},
        {percent:84,message:'Overlaying private application code while preserving runtime data…'},
        {percent:91,message:'Updating release metadata and retention…'}
      ],1400)
      try{
        const d=await api('./api/deploy.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':this.csrf},body:JSON.stringify({
          project:this.selected.id,
          runId:this.candidateRun ? this.candidateRun.id : 0,
          artifactId:this.candidateArtifact ? this.candidateArtifact.id : 0,
          commit:this.candidateCommitSha==='—'?'':this.candidateCommitSha
        })})
        this.setOperation(94,'Deployment published. Refreshing application registry…')
        this.cacheDropProject(this.selected.id)
        await this.loadProjects()
        this.setOperation(97,'Refreshing release history…')
        await this.loadReleases(true)
        this.setOperation(99,'Running post-deploy health check…')
        await this.checkHealth(true)
        this.notice='Deployed release '+d.release
        this.completeOperation('Deployment complete and health check finished.')
      }catch(e){
        this.error=e.message;this.failOperation('Deployment failed: '+e.message)
      }finally{this.busy=false;icons()}
    },
    async rollback(release){
      if(!confirm('Rollback '+this.selected.name+' to '+release+'?'))return
      this.clearMessages();this.busy=true
      this.startOperation('rollback','Rolling back '+this.selectedName,'Preparing rollback snapshot…',10,true)
      this.runEstimatedStages([
        {percent:30,message:'Loading selected release…'},
        {percent:55,message:'Restoring public application files…'},
        {percent:72,message:'Overlaying private application code…'},
        {percent:88,message:'Refreshing release metadata…'}
      ],1200)
      try{
        await api('./api/rollback.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':this.csrf},body:JSON.stringify({project:this.selected.id,release})})
        this.setOperation(94,'Rollback published. Refreshing state…')
        this.cacheDropProject(this.selected.id)
        await this.loadProjects();await this.loadReleases(true)
        this.setOperation(98,'Running health check…')
        await this.checkHealth(true)
        this.notice='Rollback complete.'
        this.completeOperation('Rollback complete and health check finished.')
      }catch(e){
        this.error=e.message;this.failOperation('Rollback failed: '+e.message)
      }finally{this.busy=false;icons()}
    },
    async checkHealth(silent=false){
      if(!this.selected)return false
      if(!silent){
        this.clearMessages();this.busy=true
        this.startOperation('health','Running health check','Checking HTTP, storage and runtime status…',20,false)
      }
      try{
        this.health=await this.singleFlight('health:'+this.selected.id,()=>api('./api/health.php?project='+encodeURIComponent(this.selected.id)))
        this.cachePut('health',this.selected.id,this.health)
        if(!silent)this.setOperation(78,'Refreshing application health state…')
        await this.loadProjects()
        if(!silent){
          this.notice=this.health.ok?'Health check passed.':'Health check needs attention.'
          this.completeOperation(this.health.ok?'Health check passed.':'Health check completed with attention required.')
        }
        return !!this.health.ok
      }catch(e){
        if(!silent){this.error=e.message;this.failOperation('Health check failed: '+e.message)}
        return false
      }finally{
        if(!silent)this.busy=false
        icons()
      }
    },
    async browse(scope='public',path='',force=false){
      if(!this.selected)return
      this.clearMessages()
      const id=this.selected.id
      const cacheKey=id+'|'+scope+'|'+path
      const cached=!force?this.cacheGet('files',cacheKey,120000):null
      if(cached){
        this.fileListing=cached
        icons()
        return
      }
      try{
        const d=await this.singleFlight('files:'+cacheKey,()=>api('./api/files.php?project='+encodeURIComponent(id)+'&scope='+encodeURIComponent(scope)+'&path='+encodeURIComponent(path)))
        this.fileListing=this.cachePut('files',cacheKey,{scope,...d.listing})
      }catch(e){this.error=e.message}
      icons()
    },
    async loadAudit(){
      if(this.userRole!=='admin')return
      try{const d=await api('./api/audit.php?limit=150');this.audit=d.events||[]}catch(e){this.error=e.message}
      icons()
    },
    async loadRuntimeInfo(){
      try{
        const d=await api('./api/runtime.php')
        this.runtimeInfo=d.runtime||null
      }catch(e){this.error=e.message}
      icons()
    },
    async loadInfrastructure(){
      try{
        const d=await api('./api/infrastructure.php')
        this.infra=d
        Object.assign(this.redisForm,{
          host:d.redis&&d.redis.host?d.redis.host:'127.0.0.1',
          port:d.redis&&d.redis.port?d.redis.port:6379,
          username:d.redis&&d.redis.username?d.redis.username:'',
          password:'',
          database:d.redis&&Number.isFinite(Number(d.redis.database))?Number(d.redis.database):0,
          prefix:d.redis&&d.redis.prefix?d.redis.prefix:'digiops:',
          timeout:d.redis&&d.redis.timeout?d.redis.timeout:1.5
        })
        if(d.varnish)Object.assign(this.varnishForm,d.varnish)
      }catch(e){this.error=e.message}
      icons()
    },
    async testRedis(save=false){
      if(this.userRole!=='admin')return
      this.clearMessages();this.busy=true
      this.startOperation('redis',save?'Saving Redis connection':'Testing Redis connection','Opening Redis connection and authenticating…',25,false)
      try{
        const payload={action:save?'redis-save':'redis-test',...this.redisForm}
        const d=await api('./api/infrastructure.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':this.csrf},body:JSON.stringify(payload)})
        this.redisStatus=d.test||null
        this.redisForm.password=''
        this.notice=save?'Redis configuration saved securely.':'Redis connection test passed.'
        this.completeOperation(save?'Redis configuration saved.':'Redis connection verified.')
        if(save)await this.loadInfrastructure()
      }catch(e){this.error=e.message;this.failOperation('Redis check failed: '+e.message)}
      finally{this.busy=false;icons()}
    },
    openTargetCreate(){
      this.targetForm={id:'',name:'',type:'agent',endpoint:'',secret:'',publicBase:'public_html',privateBase:'private_html'}
      this.generateTargetSecret()
      this.modal='target'
      icons()
    },
    generateTargetSecret(){
      const bytes=new Uint8Array(32)
      crypto.getRandomValues(bytes)
      this.targetForm.secret=Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('')
    },
    syncTargetId(){
      this.targetForm.id=this.normalizeSlug(this.targetForm.name)
    },
    async saveTarget(){
      if(this.userRole!=='admin')return
      this.clearMessages();this.busy=true
      try{
        const d=await api('./api/targets.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':this.csrf},body:JSON.stringify({action:'save',...this.targetForm})})
        this.modal=null
        await this.loadTargets()
        this.notice='Deployment target saved. Install the agent with the same shared secret, then run Test connection.'
      }catch(e){this.error=e.message}
      finally{this.busy=false;icons()}
    },
    async testTarget(id){
      if(this.userRole!=='admin')return
      this.clearMessages();this.busy=true
      this.startOperation('target','Testing deployment target','Sending a signed capability probe…',30,false)
      try{
        const d=await api('./api/targets.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':this.csrf},body:JSON.stringify({action:'test',id})})
        await this.loadTargets()
        const latency=d.result&&d.result._latencyMs?d.result._latencyMs:(d.result&&d.result.latencyMs?d.result.latencyMs:0)
        this.notice='Target verified'+(latency?' · '+latency+' ms':'')+'.'
        this.completeOperation('Target connection and capabilities verified.')
      }catch(e){this.error=e.message;this.failOperation('Target test failed: '+e.message)}
      finally{this.busy=false;icons()}
    },
    async deleteTarget(id){
      if(this.userRole!=='admin'||id==='local')return
      if(!confirm('Delete deployment target '+id+'?'))return
      this.clearMessages();this.busy=true
      try{
        await api('./api/targets.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':this.csrf},body:JSON.stringify({action:'delete',id})})
        await this.loadTargets()
        this.notice='Deployment target deleted.'
      }catch(e){this.error=e.message}
      finally{this.busy=false;icons()}
    },
    async saveVarnishPolicy(){
      if(this.userRole!=='admin')return
      this.clearMessages();this.busy=true
      try{
        const d=await api('./api/infrastructure.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':this.csrf},body:JSON.stringify({action:'varnish-save',...this.varnishForm})})
        if(d.varnish)Object.assign(this.varnishForm,d.varnish)
        if(!this.infra)this.infra={}
        this.infra.varnishPolicyConfigured=true
        this.notice='Varnish policy saved. Cloudways exclusions remain externally managed until Cloudways API is connected.'
      }catch(e){this.error=e.message}
      finally{this.busy=false;icons()}
    },
    async setTab(tab){
      if(!this.selected)return
      await this.navigateRoute(this.routeFor('project',this.selected.id,tab))
    }
  }
}

document.querySelector('#app').innerHTML=`
<div x-data="app" x-init="init()" x-cloak class="shell">
  <div x-show="!ready" class="grid min-h-screen place-items-center"><div class="text-center"><div class="brand-mark mx-auto"><i data-lucide="zap"></i></div><p class="mt-4 text-sm text-slate-500">Loading DigiOps…</p></div></div>

  <div x-show="ready && !user" class="grid min-h-screen place-items-center p-4">
    <div class="w-full max-w-md rounded-[28px] border border-slate-200 bg-white p-7 shadow-panel">
      <div class="mb-7 flex items-center gap-3"><div class="brand-mark"><i data-lucide="zap"></i></div><div><h1 class="text-xl font-bold">DigiOps</h1><p class="text-sm text-slate-500" x-text="installed?'Secure administrator sign-in':'First-run installation'"></p></div></div>
      <div x-show="error" class="mb-4 rounded-xl bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700" x-text="error"></div>
      <form x-show="!installed" @submit.prevent="doInstall()" class="space-y-4">
        <label class="block text-sm"><span class="mb-1.5 block font-semibold">Administrator name</span><input x-model="install.name" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" required></label>
        <label class="block text-sm"><span class="mb-1.5 block font-semibold">Username</span><input x-model="install.username" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" required></label>
        <label class="block text-sm"><span class="mb-1.5 block font-semibold">Password</span><input x-model="install.password" type="password" minlength="12" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" required><small class="text-slate-400">Minimum 12 characters.</small></label>
        <label class="block text-sm"><span class="mb-1.5 block font-semibold">Confirm password</span><input x-model="install.confirm" type="password" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" required></label>
        <label class="block text-sm"><span class="mb-1.5 block font-semibold">TOTP secret <span class="font-normal text-slate-400">(optional)</span></span><input x-model="install.totpSecret" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" placeholder="Base32 secret"></label>
        <button class="btn btn-primary w-full" :disabled="busy"><i data-lucide="shield-check" class="h-4 w-4"></i><span x-text="busy?'Installing…':'Install DigiOps'"></span></button>
      </form>
      <form x-show="installed" @submit.prevent="doLogin()" class="space-y-4">
        <label class="block text-sm"><span class="mb-1.5 block font-semibold">Username</span><input x-model="login.username" autocomplete="username" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" required></label>
        <label class="block text-sm"><span class="mb-1.5 block font-semibold">Password</span><input x-model="login.password" type="password" autocomplete="current-password" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" required></label>
        <label class="block text-sm"><span class="mb-1.5 block font-semibold">Authenticator code <span class="font-normal text-slate-400">(if enabled)</span></span><input x-model="login.totp" inputmode="numeric" maxlength="6" class="w-full rounded-xl border border-slate-200 px-3 py-2.5"></label>
        <button class="btn btn-primary w-full" :disabled="busy"><i data-lucide="lock-keyhole" class="h-4 w-4"></i><span x-text="busy?'Signing in…':'Sign in'"></span></button>
      </form>
    </div>
  </div>

  <div x-show="ready && user" class="app-frame flex">
    <div x-show="sidebarOpen" @click="sidebarOpen=false" class="fixed inset-0 z-40 bg-slate-950/20 lg:hidden"></div>
    <aside class="sidebar" :class="sidebarOpen?'open':''">
      <div class="mb-7 flex items-center gap-3 px-2"><div class="brand-mark"><i data-lucide="zap"></i></div><div><div class="font-bold">DigiOps</div><div class="text-xs text-slate-500">Control Plane</div></div><button @click="sidebarOpen=false" class="ml-auto lg:hidden"><i data-lucide="x"></i></button></div>
      <div class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">Workspace</div>
      <button @click="go('dashboard')" class="side-link" :class="page==='dashboard'?'active':''"><i data-lucide="layout-dashboard"></i>Dashboard</button>
      <button @click="go('projects')" class="side-link" :class="['projects','project'].includes(page)?'active':''"><i data-lucide="folder-git-2"></i>Applications<span class="ml-auto rounded-full bg-slate-200 px-2 text-[11px]" x-text="projects.length"></span></button>
      <button @click="go('targets')" class="side-link" :class="page==='targets'?'active':''" x-show="userRole==='admin'"><i data-lucide="server"></i>Targets<span class="ml-auto rounded-full bg-slate-200 px-2 text-[11px]" x-text="targets.length"></span></button>
      <button @click="go('audit')" class="side-link" :class="page==='audit'?'active':''" x-show="userRole==='admin'"><i data-lucide="file-clock"></i>Audit Log</button>
      <button @click="go('settings')" class="side-link" :class="page==='settings'?'active':''"><i data-lucide="settings-2"></i>Connections</button>
      <div class="mt-auto rounded-2xl border border-slate-200 bg-white p-3"><div class="flex items-center gap-3"><span class="grid h-9 w-9 place-items-center rounded-full bg-blue-50 text-blue-700"><i data-lucide="user-round" class="h-4 w-4"></i></span><div class="min-w-0 flex-1"><b class="block truncate text-sm" x-text="userName"></b><small class="block truncate text-slate-500" x-text="userRole"></small></div><button @click="logout()" class="icon-btn h-8 w-8 border-0 shadow-none" title="Logout"><i data-lucide="log-out" class="h-4 w-4"></i></button></div></div>
    </aside>

    <main class="min-w-0 flex-1">
      <header class="topbar"><div class="flex min-w-0 flex-1 items-center gap-3"><button @click="sidebarOpen=true" class="icon-btn lg:hidden"><i data-lucide="menu"></i></button><label class="search-field"><i data-lucide="search" class="h-4 w-4 text-slate-400"></i><input x-model="query" placeholder="Search applications, repositories, paths…"></label></div><span class="ml-3 hidden items-center gap-2 rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 sm:inline-flex"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>Secure session</span></header>

      <div class="px-4 py-6 md:px-7">
        <div x-show="notice" class="mb-4 rounded-xl px-4 py-3 text-sm font-medium" :class="noticeTone" x-text="notice"></div>
        <div x-show="error" class="mb-4 rounded-xl bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700" x-text="error"></div>
        <div x-show="operation.active" class="operation-card" :class="operationTone">
          <div class="flex items-start gap-3">
            <span class="operation-icon"><i data-lucide="refresh-cw" class="h-4 w-4 animate-spin"></i></span>
            <div class="min-w-0 flex-1">
              <div class="flex flex-wrap items-center justify-between gap-2"><b x-text="operation.title"></b><span class="text-xs font-bold" x-text="operationPercentLabel"></span></div>
              <p class="mt-1 text-sm text-slate-600" x-text="operation.message"></p>
              <div class="operation-track mt-3"><div class="operation-bar" :style="operationWidth"></div></div>
              <div class="mt-2 flex items-center justify-between gap-3 text-[11px] font-medium text-slate-500"><span x-text="operationStatusLabel"></span><span x-show="operation.estimated">Server completion is authoritative; percentage is phase-based.</span></div>
            </div>
          </div>
        </div>

        <section x-show="page==='dashboard'" class="space-y-6">
          <div class="flex flex-wrap items-end justify-between gap-4">
            <div><p class="text-sm font-medium text-blue-600">Fleet operations</p><h1 class="mt-1 text-2xl font-bold">Operations Dashboard</h1><p class="muted mt-1">Last-known fleet state across applications and deployment targets. No remote checks run just by opening this page.</p></div>
            <div class="flex flex-wrap gap-2"><button @click="go('targets')" class="btn" x-show="userRole==='admin'"><i data-lucide="server" class="h-4 w-4"></i>Targets</button><button @click="openCreate()" class="btn btn-primary"><i data-lucide="plus" class="h-4 w-4"></i>Add application</button></div>
          </div>

          <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
            <button @click="go('projects')" class="stat-card text-left"><span class="muted">Applications</span><div class="mt-3 text-3xl font-bold" x-text="stats.total"></div><small class="mt-2 block text-slate-400">Registered fleet</small></button>
            <button @click="go('targets')" class="stat-card text-left"><span class="muted">Targets</span><div class="mt-3 text-3xl font-bold" x-text="stats.targets"></div><small class="mt-2 block text-slate-400"><span x-text="stats.remoteTargets"></span> remote</small></button>
            <button @click="filter='healthy';go('projects')" class="stat-card text-left"><span class="muted">Healthy</span><div class="mt-3 text-3xl font-bold text-emerald-700" x-text="stats.healthy"></div><small class="mt-2 block text-slate-400">Last known</small></button>
            <button @click="filter='attention';go('projects')" class="stat-card text-left"><span class="muted">Attention</span><div class="mt-3 text-3xl font-bold text-rose-700" x-text="stats.attention"></div><small class="mt-2 block text-slate-400">Needs review</small></button>
            <button @click="filter='updates';go('projects')" class="stat-card text-left"><span class="muted">Updates</span><div class="mt-3 text-3xl font-bold text-amber-700" x-text="stats.updates"></div><small class="mt-2 block text-slate-400">Known available</small></button>
            <div class="stat-card"><span class="muted">Pending</span><div class="mt-3 text-3xl font-bold" x-text="stats.pending"></div><small class="mt-2 block text-slate-400">Not checked yet</small></div>
          </div>

          <div class="grid gap-5 xl:grid-cols-[1.2fr_.8fr]">
            <div class="panel">
              <div class="mb-4 flex items-start justify-between gap-3"><div><h2 class="font-bold">Attention queue</h2><p class="muted mt-1">Applications with last-known health attention or a known update.</p></div><button @click="go('projects')" class="btn">All applications</button></div>
              <div x-show="attentionProjects.length===0" class="rounded-2xl bg-emerald-50 px-4 py-6 text-center text-sm text-emerald-700">No known application needs attention.</div>
              <template x-for="p in attentionProjects" :key="p.id"><button @click="openProject(p.id)" class="row w-full text-left"><span class="min-w-0"><b class="block truncate" x-text="p.name"></b><small class="block truncate text-slate-500" x-text="p.repo"></small></span><span class="flex flex-wrap justify-end gap-2"><span x-show="p.update" class="pill border-amber-200 bg-amber-50 text-amber-700">Update</span><span class="pill"><span class="status-dot" :class="p.health==='attention'?'bg-rose-500':p.health==='healthy'?'bg-emerald-500':'bg-amber-500'"></span><span x-text="p.health||'pending'"></span></span></span></button></template>
            </div>

            <div class="panel">
              <div class="mb-4"><h2 class="font-bold">Deployment targets</h2><p class="muted mt-1">Application distribution by local/remote execution node.</p></div>
              <template x-for="t in targetSummaries" :key="t.id"><button @click="go('targets')" class="row w-full text-left"><span class="min-w-0"><b class="block truncate" x-text="t.name"></b><small class="block text-slate-500"><span x-text="t.apps"></span> applications · <span x-text="t.healthy"></span> healthy</small></span><span class="pill"><span class="status-dot" :class="t.status==='connected'?'bg-emerald-500':'bg-amber-500'"></span><span x-text="t.status"></span></span></button></template>
            </div>
          </div>

          <div class="grid gap-5 xl:grid-cols-2">
            <div class="panel">
              <div class="mb-4 flex items-start justify-between gap-3"><div><h2 class="font-bold">Recent deployments</h2><p class="muted mt-1">Most recently deployed applications from local registry metadata.</p></div><button @click="go('projects')" class="btn">View registry</button></div>
              <div x-show="recentDeployments.length===0" class="rounded-2xl bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">No deployment history recorded yet.</div>
              <template x-for="p in recentDeployments" :key="p.id"><button @click="openProject(p.id)" class="row w-full text-left"><span class="min-w-0"><b class="block truncate" x-text="p.name"></b><small class="block truncate text-slate-500" x-text="p.release"></small></span><span class="text-right text-xs text-slate-500" x-text="formatDate(p.lastDeploy)"></span></button></template>
            </div>

            <div class="panel">
              <div class="mb-4"><h2 class="font-bold">Fleet model</h2><p class="muted mt-1">Designed to scale without dashboard fan-out.</p></div>
              <div class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-2xl bg-slate-50 p-4"><b class="block text-sm">Dashboard load</b><span class="mt-1 block text-2xl font-bold">Local only</span><small class="text-slate-500">No GitHub or remote target calls</small></div>
                <div class="rounded-2xl bg-slate-50 p-4"><b class="block text-sm">Remote checks</b><span class="mt-1 block text-2xl font-bold">On demand</span><small class="text-slate-500">Per app / explicit action</small></div>
                <div class="rounded-2xl bg-slate-50 p-4"><b class="block text-sm">Application data</b><span class="mt-1 block text-2xl font-bold">Cached</span><small class="text-slate-500">Single-flight requests</small></div>
                <div class="rounded-2xl bg-slate-50 p-4"><b class="block text-sm">Navigation</b><span class="mt-1 block text-2xl font-bold">Pretty URLs</span><small class="text-slate-500">Bookmarkable app tabs</small></div>
              </div>
            </div>
          </div>
        </section>

        <section x-show="page==='projects'">
          <div class="mb-6 flex flex-wrap items-end justify-between gap-4"><div><p class="text-sm font-medium text-blue-600">Application registry</p><h1 class="mt-1 text-2xl font-bold">Applications</h1><p class="muted mt-1">Independent GitHub projects with isolated Cloudways paths.</p></div><button @click="openCreate()" class="btn btn-primary"><i data-lucide="plus" class="h-4 w-4"></i>Create application</button></div>
          <div class="mb-5 flex flex-wrap gap-2 border-b border-slate-200 pb-4"><button @click="filter='all'" class="pill" :class="filter==='all'?'border-blue-200 bg-blue-50 text-blue-700':''">All <span x-text="stats.total"></span></button><button @click="filter='updates'" class="pill">Updates <span x-text="stats.updates"></span></button><button @click="filter='healthy'" class="pill">Healthy <span x-text="stats.healthy"></span></button><button @click="filter='attention'" class="pill">Attention <span x-text="stats.attention"></span></button></div>
          <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"><template x-for="p in filteredProjects" :key="p.id"><button @click="openProject(p.id)" class="project-card"><div class="flex items-start justify-between"><div class="flex min-w-0 items-center gap-3"><span class="grid h-11 w-11 place-items-center rounded-2xl bg-blue-50 text-blue-700"><i data-lucide="folder-git-2"></i></span><span class="min-w-0"><b class="block truncate" x-text="p.name"></b><small class="block truncate text-slate-500" x-text="p.repo"></small></span></div><i data-lucide="more-vertical" class="h-5 w-5 text-slate-400"></i></div><div class="mt-5 flex flex-wrap gap-2"><span class="pill"><i data-lucide="git-branch" class="h-3.5 w-3.5"></i><span x-text="p.branch"></span></span><span class="pill"><i data-lucide="server" class="h-3.5 w-3.5"></i><span x-text="targetName(p.targetId||'local')"></span></span><span class="pill"><span class="status-dot" :class="p.health==='healthy'?'bg-emerald-500':p.health==='attention'?'bg-rose-500':'bg-amber-500'"></span><span x-text="p.health"></span></span><span x-show="p.update" class="pill border-amber-200 bg-amber-50 text-amber-700">Update available</span></div><div class="mt-auto grid grid-cols-2 gap-3 pt-6 text-xs"><div><span class="text-slate-400">URL</span><b class="mt-1 block" x-text="p.url"></b></div><div><span class="text-slate-400">Release</span><b class="mt-1 block truncate" x-text="p.release"></b></div></div></button></template></div>
        </section>

        <section x-show="page==='project' && selected">
          <div class="mb-5 flex flex-wrap items-center gap-3"><button @click="go('projects')" class="icon-btn"><i data-lucide="arrow-left"></i></button><div><h1 class="text-xl font-bold" x-text="selectedName"></h1><p class="text-sm text-slate-500" x-text="selectedRepo"></p></div><span class="pill"><i data-lucide="git-branch" class="h-3.5 w-3.5"></i><span x-text="selectedBranch"></span></span><span class="pill"><i data-lucide="server" class="h-3.5 w-3.5"></i><span x-text="targetName(selectedTargetId)"></span></span><div class="ml-auto flex gap-2"><button @click="checkUpdate()" class="btn" :disabled="busy"><i data-lucide="refresh-cw" class="h-4 w-4" :class="checkingUpdate?'animate-spin':''"></i><span x-text="checkingUpdate?'Checking…':'Check update'"></span></button><button x-show="githubNeedsConnection" @click="go('settings')" class="btn"><i data-lucide="github" class="h-4 w-4"></i>Connect GitHub</button><button @click="deploy()" class="btn btn-primary" :disabled="busy || !githubConnected"><i data-lucide="rocket" class="h-4 w-4"></i><span x-text="deploying?'Deploying…':'Deploy'"></span></button></div></div>
          <div class="mb-5 flex gap-6 overflow-x-auto border-b border-slate-200"><template x-for="t in ['overview','deploy','releases','files','health','settings']"><button @click="setTab(t)" class="tab capitalize" :class="projectTab===t?'active':''" x-text="t"></button></template></div>
          <div x-show="projectTab==='overview'" class="grid gap-5 xl:grid-cols-[1.4fr_.8fr]">
            <div class="panel"><h2 class="font-bold">Deployment configuration</h2><div class="row"><span><b class="block text-sm">Public URL</b><small class="text-slate-500">Browser route</small></span><code x-text="selectedUrl"></code></div><div class="row"><span><b class="block text-sm">Public folder</b><small class="text-slate-500">Release payload only</small></span><code class="text-xs" x-text="selectedPublicPath"></code></div><div class="row"><span><b class="block text-sm">Private folder</b><small class="text-slate-500">Runtime and metadata</small></span><code class="text-xs" x-text="selectedPrivatePath"></code></div><div class="row"><span><b class="block text-sm">Current commit</b></span><code x-text="selectedCommit"></code></div></div>
            <div class="space-y-5"><div class="panel"><h2 class="font-bold">GitHub</h2><p class="muted mt-1" x-show="!githubInfo">Not checked yet. DigiOps does not query GitHub just by opening this application.</p><div x-show="githubConnected"><div class="mt-4 flex items-center gap-2 text-sm"><i data-lucide="check-circle-2" class="h-4 w-4 text-emerald-600"></i>Connected</div><div class="mt-4 text-sm"><span class="text-slate-500">Latest workflow</span><b class="mt-1 block" x-text="latestWorkflowStatus"></b></div></div><p x-show="githubError" class="mt-3 text-sm text-rose-600" x-text="githubError"></p></div><div class="panel"><h2 class="font-bold">Update</h2><p class="muted mt-2" x-text="updateMessage"></p><div class="mt-3 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-500">Performance mode: GitHub, health, releases and files are loaded on demand and cached per application for this session.</div></div></div>
          </div>
          <div x-show="projectTab==='deploy'" class="space-y-5">
            <div x-show="githubNeedsConnection" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"><b>GitHub connection required.</b> Connect a GitHub token before checking workflows or deploying artifacts. <button type="button" @click="go('settings')" class="ml-2 font-semibold underline">Open Connections</button></div>

            <div class="panel">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div><p class="text-sm font-medium text-blue-600">Deployment Candidate</p><h2 class="mt-1 text-xl font-bold">Exact build identification</h2><p class="muted mt-2">Review the successful workflow, artifact and commit that DigiOps will deploy. These identifiers are passed back to the deploy endpoint to prevent candidate drift.</p></div>
                <span class="pill" :class="candidateReady?'border-emerald-200 bg-emerald-50 text-emerald-700':'border-amber-200 bg-amber-50 text-amber-700'"><span class="status-dot" :class="candidateReady?'bg-emerald-500':'bg-amber-500'"></span><span x-text="deployableStatusText"></span></span>
              </div>

              <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="stat-card"><span class="muted">Workflow run</span><div class="mt-2 text-xl font-bold" x-text="candidateRunNumber"></div><code class="mt-1 block text-xs" x-text="candidateRunId"></code></div>
                <div class="stat-card"><span class="muted">Artifact ID</span><div class="mt-2 text-xl font-bold" x-text="candidateArtifactId"></div><div class="mt-1 truncate text-xs text-slate-500" x-text="candidateArtifactName"></div></div>
                <div class="stat-card"><span class="muted">Commit</span><code class="mt-2 block text-base font-bold" x-text="candidateCommitShort"></code><div class="mt-1 truncate text-xs text-slate-500" x-text="candidateCommitAuthor"></div></div>
                <div class="stat-card"><span class="muted">Artifact size</span><div class="mt-2 text-xl font-bold" x-text="candidateArtifactSize"></div><div class="mt-1 text-xs text-slate-500">Keep <span x-text="selectedRetention"></span> releases</div></div>
              </div>

              <div class="mt-5 grid gap-4 lg:grid-cols-2">
                <div class="rounded-2xl border border-slate-200 p-4">
                  <h3 class="font-bold">Latest successful build</h3>
                  <div class="row"><span class="muted">Workflow</span><b x-text="candidateRun&&candidateRun.name?candidateRun.name:'—'"></b></div>
                  <div class="row"><span class="muted">Build title</span><b class="max-w-[65%] text-right" x-text="candidateRun&&candidateRun.title?candidateRun.title:'—'"></b></div>
                  <div class="row"><span class="muted">Branch</span><code x-text="candidateRun&&candidateRun.branch?candidateRun.branch:selectedBranch"></code></div>
                  <div class="row"><span class="muted">Created</span><span x-text="candidateRun&&candidateRun.createdAt?candidateRun.createdAt:'—'"></span></div>
                  <div class="row"><span class="muted">Conclusion</span><b x-text="candidateRun&&candidateRun.conclusion?candidateRun.conclusion:'—'"></b></div>
                  <div class="row"><span class="muted">Attempt</span><span x-text="candidateRun&&candidateRun.attempt?candidateRun.attempt:'—'"></span></div>
                </div>
                <div class="rounded-2xl border border-slate-200 p-4">
                  <h3 class="font-bold">Artifact</h3>
                  <div class="row"><span class="muted">Configured name</span><code x-text="selectedArtifactName"></code></div>
                  <div class="row"><span class="muted">Selected name</span><code x-text="candidateArtifactName"></code></div>
                  <div class="row"><span class="muted">Match rule</span><span x-text="candidateArtifact&&candidateArtifact.match?candidateArtifact.match:'—'"></span></div>
                  <div class="row"><span class="muted">Created</span><span x-text="candidateArtifact&&candidateArtifact.createdAt?candidateArtifact.createdAt:'—'"></span></div>
                  <div class="row"><span class="muted">Expires</span><span x-text="candidateArtifactExpires"></span></div>
                  <div class="row"><span class="muted">Artifacts in run</span><b x-text="candidate&&candidate.artifactCount?candidate.artifactCount:'—'"></b></div>
                </div>
              </div>

              <div class="mt-5 rounded-2xl bg-slate-50 p-4">
                <h3 class="font-bold">Commit details</h3>
                <div class="mt-3 grid gap-3 md:grid-cols-[160px_1fr] text-sm"><span class="muted">Full SHA</span><code class="break-all" x-text="candidateCommitSha"></code><span class="muted">Message</span><span x-text="candidateCommitMessage"></span><span class="muted">Author</span><span x-text="candidateCommitAuthor"></span><span class="muted">Commit date</span><span x-text="candidateCommitDate"></span></div>
              </div>
            </div>

            <div class="panel">
            <div class="flex flex-wrap items-start justify-between gap-4">
              <div><p class="text-sm font-medium text-emerald-600">Runtime identity</p><h2 class="mt-1 text-xl font-bold">Installed DigiOps version</h2><p class="muted mt-2">Shows the exact build currently running on this Cloudways application.</p></div>
              <span class="pill" :class="runtimeInfo&&runtimeInfo.identityVerified?'border-emerald-200 bg-emerald-50 text-emerald-700':'border-amber-200 bg-amber-50 text-amber-800'">
                <span class="status-dot" :class="runtimeInfo&&runtimeInfo.identityVerified?'bg-emerald-500':'bg-amber-500'"></span>
                <span x-text="runtimeInfo&&runtimeInfo.identityVerified?'Verified build':'Identity not verified'"></span>
              </span>
            </div>
            <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
              <div class="stat-card"><span class="muted">App version</span><div class="mt-2 text-xl font-bold" x-text="runtimeInfo&&runtimeInfo.version?runtimeInfo.version:'—'"></div></div>
              <div class="stat-card"><span class="muted">CI run</span><div class="mt-2 text-xl font-bold" x-text="runtimeInfo&&runtimeInfo.ciRunNumber?'#'+runtimeInfo.ciRunNumber:'—'"></div><div class="mt-1 text-xs text-slate-500" x-text="runtimeInfo&&runtimeInfo.ciRunId?'ID '+runtimeInfo.ciRunId:'No CI identity'"></div></div>
              <div class="stat-card"><span class="muted">Artifact ID</span><div class="mt-2 text-xl font-bold" x-text="runtimeInfo&&runtimeInfo.artifactId?runtimeInfo.artifactId:'—'"></div></div>
              <div class="stat-card"><span class="muted">PHP runtime</span><div class="mt-2 text-xl font-bold" x-text="runtimeInfo&&runtimeInfo.phpVersion?runtimeInfo.phpVersion:'—'"></div></div>
            </div>
            <div class="mt-5 rounded-2xl bg-slate-50 p-4 text-sm">
              <div class="grid gap-3 md:grid-cols-[170px_1fr]">
                <span class="muted">Source commit</span><code class="break-all" x-text="runtimeInfo&&runtimeInfo.sourceSha?runtimeInfo.sourceSha:'—'"></code>
                <span class="muted">Branch</span><span x-text="runtimeInfo&&runtimeInfo.branch?runtimeInfo.branch:'—'"></span>
                <span class="muted">Built</span><span x-text="runtimeInfo&&runtimeInfo.builtAt?formatDate(runtimeInfo.builtAt):'—'"></span>
                <span class="muted">Installed</span><span x-text="runtimeInfo&&runtimeInfo.installedAt?formatDate(runtimeInfo.installedAt):'—'"></span>
                <span class="muted">Artifact created</span><span x-text="runtimeInfo&&runtimeInfo.artifactCreatedAt?formatDate(runtimeInfo.artifactCreatedAt):'—'"></span>
                <span class="muted">Build manifests</span><span x-text="runtimeInfo&&runtimeInfo.buildManifestPresent&&runtimeInfo.installManifestPresent?'Build + install manifests present':'Legacy/incomplete identity metadata'"></span>
              </div>
            </div>
            <div class="mt-5 flex flex-wrap gap-2"><button @click="loadRuntimeInfo()" class="btn"><i data-lucide="refresh-cw" class="h-4 w-4"></i>Verify running version</button></div>
          </div>

          <div class="grid gap-5 xl:grid-cols-2">
              <div class="panel">
                <h3 class="font-bold">Current deployment</h3>
                <div class="row"><span class="muted">Release</span><code x-text="deployedRelease"></code></div>
                <div class="row"><span class="muted">Commit</span><code x-text="deployedCommitShort"></code></div>
                <div class="row"><span class="muted">Last deployed</span><span x-text="deployedLastDeploy"></span></div>
                <div class="row"><span class="muted">Health</span><b x-text="selected&&selected.health?selected.health:'pending'"></b></div>
              </div>
              <div class="panel">
                <h3 class="font-bold">Update comparison</h3>
                <div class="row"><span class="muted">Branch HEAD</span><code x-text="branchHeadShort"></code></div>
                <div class="row"><span class="muted">Deployable commit</span><code x-text="candidateCommitShort"></code></div>
                <div class="row"><span class="muted">Source commits ahead</span><b x-text="candidate&&candidate.sourceCommitsAhead!==null?candidate.sourceCommitsAhead:'—'"></b></div>
                <div class="row"><span class="muted">Deployable commits ahead</span><b x-text="candidate&&candidate.deployableCommitsAhead!==null?candidate.deployableCommitsAhead:'—'"></b></div>
                <div x-show="candidate&&candidate.branchAhead" class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">Branch HEAD is newer than the latest successful artifact. DigiOps will deploy only the successful candidate shown above.</div>
              </div>
            </div>

            <div class="panel">
              <div class="flex flex-wrap items-center justify-between gap-4"><div><h3 class="font-bold">Approval</h3><p class="muted mt-1">DigiOps will snapshot the current public release, overlay private application code without deleting runtime data, validate the ZIP and publish this exact candidate.</p></div><button @click="deploy()" class="btn btn-primary" :disabled="busy || !githubConnected || !candidateReady"><i data-lucide="rocket" class="h-4 w-4"></i><span x-text="candidateReady?'Deploy '+candidateRunNumber:'No deployable build'"></span></button></div>
            </div>
          </div>
          <div x-show="projectTab==='releases'" class="table-wrap releases-table">
            <div class="release-head"><span>Release</span><span>Type</span><span>Commit</span><span>Created</span><span>Size</span><span>Action</span></div>
            <template x-for="r in releases" :key="r.id">
              <div class="release-row" :class="isCurrentRelease(r)?'release-current':''">
                <div class="min-w-0"><div class="flex items-center gap-2"><span class="truncate font-semibold" x-text="r.id" :title="r.id"></span><span x-show="isCurrentRelease(r)" class="pill border-emerald-200 bg-emerald-50 text-emerald-700">Current</span></div></div>
                <div><span class="pill capitalize" x-text="releaseType(r)"></span></div>
                <code class="truncate text-xs" x-text="releaseCommitShort(r)" :title="r.commit || 'snapshot'"></code>
                <span class="text-sm text-slate-600" x-text="releaseCreated(r)"></span>
                <span class="text-sm font-medium text-slate-600" x-text="releaseSize(r)"></span>
                <div><button @click="rollback(r.id)" class="btn py-1.5 text-xs" :disabled="busy || isCurrentRelease(r)"><span x-text="isCurrentRelease(r)?'Current':'Rollback'"></span></button></div>
              </div>
            </template>
            <div x-show="releases.length===0" class="p-6 text-sm text-slate-500">No releases yet.</div>
          </div>
          <div x-show="projectTab==='files'" class="panel"><div class="mb-4 flex gap-2"><button @click="browse('public','')" class="btn">Public</button><button @click="browse('private','')" class="btn">Private</button></div><div class="mb-3 font-mono text-xs text-slate-500" x-text="filePathLabel"></div><div class="divide-y divide-slate-100"><template x-for="f in fileItems" :key="f.name"><div class="flex items-center justify-between py-3 text-sm"><span class="flex items-center gap-2"><i data-lucide="file-text" class="h-4 w-4 text-slate-400"></i><span x-text="f.name"></span></span><span class="text-xs text-slate-400" x-text="f.type==='dir'?'Folder':f.size+' B'"></span></div></template></div></div>
          <div x-show="projectTab==='health'" class="grid gap-4 md:grid-cols-3"><div class="stat-card"><i data-lucide="heart-pulse" class="h-5 w-5 text-emerald-600"></i><h3 class="mt-3 font-bold">HTTP</h3><p class="muted mt-1" x-text="healthHttpText"></p></div><div class="stat-card"><i data-lucide="hard-drive" class="h-5 w-5 text-blue-600"></i><h3 class="mt-3 font-bold">Storage</h3><p class="muted mt-1" x-text="healthStorageText"></p></div><div class="stat-card"><i data-lucide="server" class="h-5 w-5 text-violet-600"></i><h3 class="mt-3 font-bold">Runtime</h3><p class="muted mt-1" x-text="healthRuntimeText"></p></div></div>
          <div x-show="projectTab==='settings'" class="panel"><h2 class="font-bold">Application settings</h2><div class="mt-5 grid gap-4 md:grid-cols-2"><div><span class="muted">Repository</span><b class="mt-1 block" x-text="selectedRepo"></b></div><div><span class="muted">Branch</span><b class="mt-1 block" x-text="selectedBranch"></b></div><div><span class="muted">Artifact</span><b class="mt-1 block" x-text="selectedArtifactName"></b></div><div><span class="muted">Health path</span><b class="mt-1 block" x-text="selectedHealthPath"></b></div><div><span class="muted">Deployment target</span><b class="mt-1 block" x-text="targetName(selectedTargetId)"></b></div><div><span class="muted">Public path</span><code class="mt-1 block text-xs" x-text="selectedPublicPath"></code></div><div><span class="muted">Private path</span><code class="mt-1 block text-xs" x-text="selectedPrivatePath"></code></div></div></div>
        </section>

        <section x-show="page==='targets'" class="space-y-5">
          <div class="flex flex-wrap items-end justify-between gap-4"><div><p class="text-sm font-medium text-blue-600">Infrastructure</p><h1 class="mt-1 text-2xl font-bold">Deployment Targets</h1><p class="muted mt-1">Local and remote Cloudways execution nodes. Targets are contacted only when an assigned application action is requested.</p></div><div class="flex gap-2"><a  :href="appUrl('api/agent-package.php')" class="btn"><i data-lucide="package-check" class="h-4 w-4"></i>Download agent</a><button @click="openTargetCreate()" class="btn btn-primary"><i data-lucide="plus" class="h-4 w-4"></i>Add target</button></div></div>
          <div class="grid gap-4 xl:grid-cols-2">
            <template x-for="t in targets" :key="t.id"><div class="panel">
              <div class="flex items-start justify-between gap-3"><div class="min-w-0"><div class="flex items-center gap-2"><h2 class="truncate text-lg font-bold" x-text="t.name"></h2><span class="pill" x-text="t.type"></span></div><code class="mt-1 block truncate text-xs text-slate-500" x-text="t.id"></code></div><span class="pill"><span class="status-dot" :class="t.status==='connected'?'bg-emerald-500':'bg-amber-500'"></span><span x-text="t.status"></span></span></div>
              <div class="mt-4 space-y-2 text-sm">
                <div class="row"><span class="muted">Endpoint</span><code class="max-w-[65%] truncate" x-text="t.id==='local'?'Embedded':t.endpoint"></code></div>
                <div class="row"><span class="muted">Agent</span><span x-text="t.id==='local'?'embedded':(t.agentVersion||'Not verified')"></span></div>
                <div class="row"><span class="muted">Latency</span><span x-text="t.latencyMs!==null&&t.latencyMs!==undefined?t.latencyMs+' ms':'—'"></span></div>
                <div class="row"><span class="muted">Secret</span><span x-text="t.secretSet?'Configured':'Missing'"></span></div>
                <div class="row"><span class="muted">Capabilities</span><span class="max-w-[65%] text-right text-xs" x-text="t.capabilities&&t.capabilities.length?t.capabilities.join(', '):'Not verified'"></span></div>
              </div>
              <div class="mt-5 flex flex-wrap gap-2"><button @click="testTarget(t.id)" class="btn" :disabled="busy"><i data-lucide="activity" class="h-4 w-4"></i>Test connection</button><button x-show="t.id!=='local'" @click="deleteTarget(t.id)" class="btn" :disabled="busy">Delete</button></div>
            </div></template>
          </div>
          <div class="panel"><h2 class="font-bold">Remote agent installation</h2><ol class="mt-3 list-decimal space-y-2 pl-5 text-sm text-slate-600"><li>Download <code>digiops-agent.php</code> and place it in the remote Cloudways application's public folder at a dedicated HTTPS URL.</li><li>Set <code>DIGIOPS_AGENT_SECRET</code> on the remote application to the same 64-character secret generated when creating the target.</li><li>Add the agent URL as the target endpoint, save it, then run <b>Test connection</b>.</li><li>Only after capability verification assign production applications to that target.</li></ol></div>
        </section>

        <section x-show="page==='settings'" class="space-y-5">
          <div><p class="text-sm font-medium text-blue-600">Connections & infrastructure</p><h1 class="mt-1 text-2xl font-bold">Settings</h1><p class="muted mt-1">Manage external services and verify the runtime environment from one place.</p></div>

          <div class="grid gap-5 xl:grid-cols-2">
            <div class="panel">
              <p class="text-sm font-medium text-blue-600">Connection</p><h2 class="mt-1 text-xl font-bold">GitHub access</h2><p class="muted mt-2">Fine-grained token with repository, Actions and artifact read access. The token is encrypted in private storage and never returned to the browser.</p>
              <form @submit.prevent="connectGithub()" class="mt-5 space-y-4">
                <label class="block text-sm"><span class="mb-1.5 block font-semibold">GitHub token</span><input x-model="github.token" type="password" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" required></label>
                <label class="block text-sm"><span class="mb-1.5 block font-semibold">Test repository</span><input x-model="github.testRepo" class="w-full rounded-xl border border-slate-200 px-3 py-2.5"></label>
                <button class="btn btn-primary" :disabled="busy"><i data-lucide="github" class="h-4 w-4"></i>Connect GitHub</button>
              </form>
            </div>

            <div class="panel">
              <div class="flex items-start justify-between gap-3"><div><p class="text-sm font-medium text-violet-600">Infrastructure</p><h2 class="mt-1 text-xl font-bold">Redis</h2><p class="muted mt-2">Cloudways mode uses only the application Redis details shown in your Cloudways panel.</p></div><span class="pill" :class="infra&&infra.redis&&infra.redis.configured?'border-emerald-200 bg-emerald-50 text-emerald-700':'border-slate-200'"><span class="status-dot" :class="infra&&infra.redis&&infra.redis.configured?'bg-emerald-500':'bg-slate-400'"></span><span x-text="infra&&infra.redis&&infra.redis.configured?'Configured':'Not configured'"></span></span></div>

              <div class="mt-4 inline-flex rounded-xl border border-slate-200 bg-slate-50 p-1 text-sm font-semibold">
                <button type="button" @click="redisMode='cloudways';redisAdvanced=false" class="rounded-lg px-3 py-2" :class="redisMode==='cloudways'?'bg-white text-slate-950 shadow-sm':'text-slate-500'">Cloudways</button>
                <button type="button" @click="redisMode='custom';redisAdvanced=true" class="rounded-lg px-3 py-2" :class="redisMode==='custom'?'bg-white text-slate-950 shadow-sm':'text-slate-500'">Advanced / Custom</button>
              </div>

              <div class="mt-5 grid gap-4 sm:grid-cols-3">
                <label class="text-sm"><span class="mb-1.5 block font-semibold">Prefix</span><input x-model="redisForm.prefix" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" placeholder="app-prefix:"></label>
                <label class="text-sm"><span class="mb-1.5 block font-semibold">Username</span><input x-model="redisForm.username" class="w-full rounded-xl border border-slate-200 px-3 py-2.5"></label>
                <label class="text-sm"><span class="mb-1.5 block font-semibold">Password</span><input x-model="redisForm.password" type="password" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" placeholder="Leave blank to keep saved password"></label>
              </div>

              <div x-show="redisAdvanced" class="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <div class="mb-3"><b>Advanced connection</b><p class="mt-1 text-xs text-slate-500">Only use this for non-Cloudways Redis or when you know the endpoint details.</p></div>
                <div class="grid gap-4 sm:grid-cols-2">
                  <label class="text-sm"><span class="mb-1.5 block font-semibold">Host</span><input x-model="redisForm.host" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5" placeholder="127.0.0.1"></label>
                  <label class="text-sm"><span class="mb-1.5 block font-semibold">Port</span><input x-model="redisForm.port" type="number" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5"></label>
                  <label class="text-sm"><span class="mb-1.5 block font-semibold">Database</span><input x-model="redisForm.database" type="number" min="0" max="15" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5"></label>
                  <label class="text-sm"><span class="mb-1.5 block font-semibold">Timeout</span><input x-model="redisForm.timeout" type="number" min="0.3" max="5" step="0.1" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5"></label>
                </div>
              </div>

              <div class="mt-4 rounded-2xl bg-blue-50 px-4 py-3 text-xs leading-relaxed text-blue-900"><b>Cloudways mode:</b> paste only the Prefix, Username and Password from Application → Access Details → Redis. DigiOps keeps the password encrypted in private storage.</div>

              <div x-show="redisStatus" class="mt-4 rounded-2xl bg-slate-50 p-4 text-sm">
                <div class="grid gap-2 sm:grid-cols-4"><div><span class="muted">Latency</span><b class="mt-1 block" x-text="redisStatus&&redisStatus.latencyMs?redisStatus.latencyMs+' ms':'—'"></b></div><div><span class="muted">Driver</span><b class="mt-1 block" x-text="redisStatus&&redisStatus.driver?redisStatus.driver:'—'"></b></div><div><span class="muted">Version</span><b class="mt-1 block" x-text="redisStatus&&redisStatus.version?redisStatus.version:'—'"></b></div><div><span class="muted">Memory</span><b class="mt-1 block" x-text="redisStatus&&redisStatus.memory?redisStatus.memory:'—'"></b></div></div>
              </div>
              <div class="mt-5 flex flex-wrap gap-2"><button @click="testRedis(false)" class="btn" :disabled="busy||userRole!=='admin'"><i data-lucide="activity" class="h-4 w-4"></i>Test connection</button><button @click="testRedis(true)" class="btn btn-primary" :disabled="busy||userRole!=='admin'"><i data-lucide="shield-check" class="h-4 w-4"></i>Save securely</button></div>
            </div>
          </div>

          <div class="panel">
            <div class="flex flex-wrap items-start justify-between gap-4"><div><p class="text-sm font-medium text-amber-600">Cache policy</p><h2 class="mt-1 text-xl font-bold">Varnish</h2><p class="muted mt-2">DigiOps should bypass Varnish because it is an authenticated control plane. Keep Varnish enabled globally for public sites, but exclude DigiOps.</p></div><div class="flex flex-wrap gap-2"><span class="pill" :class="infra&&infra.varnishPolicyConfigured?'border-emerald-200 bg-emerald-50 text-emerald-700':'border-amber-200 bg-amber-50 text-amber-800'"><span class="status-dot" :class="infra&&infra.varnishPolicyConfigured?'bg-emerald-500':'bg-amber-500'"></span><span x-text="infra&&infra.varnishPolicyConfigured?'Policy saved':'Policy not saved'"></span></span><span class="pill border-slate-200 bg-slate-50 text-slate-600">Cloudways API: Not connected</span></div></div>
            <div class="mt-5 grid gap-4 md:grid-cols-3">
              <label class="text-sm"><span class="mb-1.5 block font-semibold">Bypass path</span><input x-model="varnishForm.bypassPath" class="w-full rounded-xl border border-slate-200 px-3 py-2.5"></label>
              <label class="text-sm"><span class="mb-1.5 block font-semibold">Session cookie</span><input x-model="varnishForm.sessionCookie" class="w-full rounded-xl border border-slate-200 px-3 py-2.5"></label>
              <label class="text-sm"><span class="mb-1.5 block font-semibold">API path</span><input x-model="varnishForm.apiPath" class="w-full rounded-xl border border-slate-200 px-3 py-2.5"></label>
            </div>
            <div class="mt-4 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900"><b>Recommended Cloudways exclusions</b><div class="mt-2 grid gap-1 font-mono text-xs"><span x-text="'URL: '+varnishForm.bypassPath"></span><span x-text="'URL: '+varnishForm.apiPath"></span><span x-text="'Cookie: '+varnishForm.sessionCookie"></span></div><p class="mt-3 text-xs">After changing exclusions in Cloudways, purge Varnish once. <b>Policy saved</b> means DigiOps has stored your intended exclusions. <b>Cloudways API: Not connected</b> only means DigiOps cannot directly read or toggle the Cloudways Varnish service yet.</p></div>
            <div class="mt-5 flex gap-2"><button @click="saveVarnishPolicy()" class="btn btn-primary" :disabled="busy||userRole!=='admin'"><i data-lucide="shield-check" class="h-4 w-4"></i>Save policy</button></div>
          </div>
        </section>

        <section x-show="page==='audit'">
          <div class="panel"><div class="mb-4 flex justify-between"><div><h1 class="text-xl font-bold">Audit Log</h1><p class="muted">Hash-chained operational events.</p></div><button @click="loadAudit()" class="btn"><i data-lucide="refresh-cw" class="h-4 w-4"></i>Refresh</button></div><div class="divide-y divide-slate-100"><template x-for="a in audit" :key="a.hash"><div class="py-3 text-sm"><div class="flex flex-wrap justify-between gap-2"><b x-text="a.event"></b><span class="text-xs text-slate-400" x-text="a.time"></span></div><div class="mt-1 text-xs text-slate-500"><span x-text="a.actor"></span> · <code x-text="auditHashPrefix(a)"></code></div></div></template></div></div>
        </section>
      </div>

      <footer x-show="user" class="mt-8 border-t border-slate-200 px-1 py-5 text-xs text-slate-500">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <span><b class="font-semibold text-slate-700">DigiOps</b> <span x-text="'v'+runtimeVersion"></span></span>
            <span>Artifact <code class="rounded bg-slate-100 px-1.5 py-0.5 text-slate-700" x-text="runtimeArtifactId"></code></span>
            <button type="button" @click="copyText(runtimeSourceFull)" class="inline-flex items-center gap-1 hover:text-slate-900" :title="runtimeSourceFull||'Source unavailable'">
              Source <code class="rounded bg-slate-100 px-1.5 py-0.5 text-slate-700" x-text="runtimeSourceShort"></code>
            </button>
          </div>
          <span x-show="runtimeInfo&&runtimeInfo.identityVerified" class="inline-flex items-center gap-1 text-emerald-700"><span class="status-dot bg-emerald-500"></span>Verified build</span>
        </div>
      </footer>
    </main>
  </div>

  <div x-show="modal==='target'" class="modal-backdrop"><div class="modal" @click.outside="modal=null"><div class="flex items-center justify-between border-b border-slate-200 px-6 py-5"><div><h2 class="text-lg font-bold">Add deployment target</h2><p class="muted">Connect another Cloudways application or server through the signed DigiOps agent.</p></div><button @click="modal=null" class="icon-btn"><i data-lucide="x"></i></button></div><form @submit.prevent="saveTarget()" class="space-y-4 p-6"><div class="grid gap-4 md:grid-cols-2"><label class="text-sm"><span class="mb-1.5 block font-semibold">Target name</span><input x-model="targetForm.name" @input="syncTargetId()" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" required></label><label class="text-sm"><span class="mb-1.5 block font-semibold">Target ID</span><input x-model="targetForm.id" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" required></label></div><label class="block text-sm"><span class="mb-1.5 block font-semibold">Agent HTTPS endpoint</span><input x-model="targetForm.endpoint" placeholder="https://remote.example.com/digiops-agent.php" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" required></label><label class="block text-sm"><span class="mb-1.5 block font-semibold">Shared secret</span><div class="flex gap-2"><input x-model="targetForm.secret" class="min-w-0 flex-1 rounded-xl border border-slate-200 px-3 py-2.5 font-mono text-xs" minlength="32" required><button type="button" @click="generateTargetSecret()" class="btn">Generate</button></div><small class="text-slate-500">Store the same secret as DIGIOPS_AGENT_SECRET on the remote application.</small></label><div class="flex justify-end gap-2"><button type="button" @click="modal=null" class="btn">Cancel</button><button class="btn btn-primary" :disabled="busy">Save target</button></div></form></div></div>

  <div x-show="modal==='create'" class="modal-backdrop"><div class="modal" @click.outside="modal=null"><div class="flex items-center justify-between border-b border-slate-200 px-6 py-5"><div><h2 class="text-lg font-bold">Create application</h2><p class="muted">Map a repository to isolated Cloudways folders.</p></div><button @click="modal=null" class="icon-btn"><i data-lucide="x"></i></button></div><form @submit.prevent="saveProject()" class="space-y-4 p-6"><div class="grid gap-4 md:grid-cols-2"><label class="text-sm"><span class="mb-1.5 block font-semibold">Application name</span><input id="digiops-app-name" x-model="form.name" @input="slugify()" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" required></label><label class="text-sm"><span class="mb-1.5 block font-semibold">Slug</span><input id="digiops-app-slug" x-model="form.slug" @input="syncSlug($event.target.value)" autocomplete="off" spellcheck="false" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" required></label></div><label class="block text-sm"><span class="mb-1.5 block font-semibold">Repository</span><input x-model="form.repo" placeholder="owner/repository" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" required></label><label class="block text-sm"><span class="mb-1.5 block font-semibold">Deployment target</span><select x-model="form.targetId" class="w-full rounded-xl border border-slate-200 px-3 py-2.5"><template x-for="t in targets" :key="t.id"><option :value="t.id" x-text="t.name"></option></template></select></label><div x-show="form.targetId!=='local'" class="rounded-2xl border border-blue-100 bg-blue-50/50 p-4"><div class="grid gap-4"><label class="text-sm"><span class="mb-1.5 block font-semibold">Application URL</span><input x-model="form.url" placeholder="https://app.example.com/" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5"></label><div class="grid gap-4 md:grid-cols-2"><label class="text-sm"><span class="mb-1.5 block font-semibold">Remote public path</span><input x-model="form.publicPath" placeholder="public_html/ or public_html/app/" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5"></label><label class="text-sm"><span class="mb-1.5 block font-semibold">Remote private path</span><input x-model="form.privatePath" placeholder="private_html/ or private_html/app/" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5"></label></div></div></div><div class="grid gap-4 md:grid-cols-2"><label class="text-sm"><span class="mb-1.5 block font-semibold">Branch</span><input x-model="form.branch" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" required></label><label class="text-sm"><span class="mb-1.5 block font-semibold">Artifact name</span><input x-model="form.artifactName" class="w-full rounded-xl border border-slate-200 px-3 py-2.5"></label></div><div class="grid gap-4 md:grid-cols-2"><label class="text-sm"><span class="mb-1.5 block font-semibold">Health path</span><input x-model="form.healthPath" class="w-full rounded-xl border border-slate-200 px-3 py-2.5"></label><label class="text-sm"><span class="mb-1.5 block font-semibold">Keep releases</span><input x-model="form.retention" type="number" min="1" max="20" class="w-full rounded-xl border border-slate-200 px-3 py-2.5"></label></div><div class="rounded-2xl bg-slate-50 p-4 text-sm"><b>Automatic paths</b><div id="digiops-public-path-preview" class="mt-2 font-mono text-xs text-slate-500">public_html/{slug}/</div><div id="digiops-private-path-preview" class="font-mono text-xs text-slate-500">private_html/{slug}/</div></div><div class="flex justify-end gap-2"><button type="button" @click="modal=null" class="btn">Cancel</button><button class="btn btn-primary" :disabled="busy">Create application</button></div></form></div></div>
</div>`

Alpine.data('app',app)
Alpine.start()

document.addEventListener('input',(event)=>{
  const target=event.target
  if(!(target instanceof HTMLInputElement)) return
  if(target.id==='digiops-app-slug'){
    renderNativePathPreview()
    return
  }
  if(target.id==='digiops-app-name'){
    setTimeout(renderNativePathPreview,0)
  }
})
document.addEventListener('focusin',(event)=>{
  const target=event.target
  if(target instanceof HTMLInputElement && (target.id==='digiops-app-name' || target.id==='digiops-app-slug')){
    setTimeout(renderNativePathPreview,0)
  }
})
icons()
