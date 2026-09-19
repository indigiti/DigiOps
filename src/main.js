import Alpine from '@alpinejs/csp'
import { createIcons, Activity, AppWindow, ArrowLeft, Boxes, CheckCircle2, ChevronDown, CircleGauge, Cloud, FileClock, FileText, FolderGit2, GitBranch, Github, Globe2, HardDrive, HeartPulse, History, LayoutDashboard, ListFilter, LockKeyhole, LogOut, Menu, MoreVertical, PackageCheck, Plus, RefreshCw, Rocket, Search, Server, Settings2, ShieldCheck, UserRound, X, Zap } from 'lucide'
import './styles.css'

window.Alpine = Alpine
const ICONS={Activity,AppWindow,ArrowLeft,Boxes,CheckCircle2,ChevronDown,CircleGauge,Cloud,FileClock,FileText,FolderGit2,GitBranch,Github,Globe2,HardDrive,HeartPulse,History,LayoutDashboard,ListFilter,LockKeyhole,LogOut,Menu,MoreVertical,PackageCheck,Plus,RefreshCw,Rocket,Search,Server,Settings2,ShieldCheck,UserRound,X,Zap}
const icons=()=>queueMicrotask(()=>createIcons({icons:ICONS}))

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
  const res=await fetch(url,{credentials:'same-origin',cache:'no-store',...options})
  const data=await res.json().catch(()=>({error:'INVALID_RESPONSE'}))
  if(!res.ok) throw new Error(data.error||('HTTP_'+res.status))
  return data
}

function app(){
  return {
    ready:false, installed:false, user:null, csrf:null, authMode:'login',
    sidebarOpen:false, page:'dashboard', projectTab:'overview', query:'', filter:'all',
    projects:[], selectedId:null, releases:[], githubInfo:null, fileListing:null, health:null, audit:[],
    modal:null, busy:false, notice:'', error:'',
    login:{username:'',password:'',totp:''},
    install:{name:'Administrator',username:'admin',password:'',confirm:'',totpSecret:''},
    github:{token:'',testRepo:'indigiti/DigiOps'},
    form:{name:'',repo:'',branch:'main',slug:'',artifactName:'digiops-release',healthPath:'/',retention:5},

    async init(){
      await this.bootstrap()
      icons()
    },
    async bootstrap(){
      this.clearMessages()
      try{
        const s=await api('./api/session.php')
        this.installed=s.installed
        this.user=s.user
        this.csrf=s.csrf
        if(this.user) await this.loadProjects()
      }catch(e){this.error=e.message}
      finally{this.ready=true;icons()}
    },
    clearMessages(){this.notice='';this.error=''},
    async doInstall(){
      this.clearMessages()
      if(this.install.password!==this.install.confirm){this.error='PASSWORD_CONFIRM_MISMATCH';return}
      this.busy=true
      try{
        const d=await api('./api/install.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(this.install)})
        this.installed=true;this.user=d.user;this.csrf=d.csrf;this.notice='DigiOps installed successfully.'
        await this.loadProjects()
      }catch(e){this.error=e.message}
      finally{this.busy=false;icons()}
    },
    async doLogin(){
      this.clearMessages();this.busy=true
      try{
        const d=await api('./api/login.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(this.login)})
        this.user=d.user;this.csrf=d.csrf;this.login.password='';this.notice='Signed in.'
        await this.loadProjects()
      }catch(e){this.error=e.message}
      finally{this.busy=false;icons()}
    },
    async logout(){
      this.clearMessages()
      try{await api('./api/logout.php',{method:'POST',headers:{'X-CSRF-Token':this.csrf}})}catch{}
      this.user=null;this.csrf=null;this.projects=[];this.page='dashboard';icons()
    },
    async loadProjects(){
      const d=await api('./api/projects.php');this.projects=d.projects||[];icons()
    },
    get stats(){
      return {total:this.projects.length,updates:this.projects.filter(p=>p.update).length,healthy:this.projects.filter(p=>p.health==='healthy').length,attention:this.projects.filter(p=>p.health==='attention').length}
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
    go(page){this.page=page;this.sidebarOpen=false;this.clearMessages();if(page==='audit')this.loadAudit();icons()},
    async openProject(id){
      this.selectedId=id;this.page='project';this.projectTab='overview';this.githubInfo=null;this.releases=[];this.fileListing=null;this.health=null;this.sidebarOpen=false;icons()
      await Promise.allSettled([this.loadGithubInfo(),this.loadReleases()])
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
      Object.assign(this.form,{name:'',repo:'',branch:'main',slug:'',artifactName:'digiops-release',healthPath:'/',retention:5})
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
        const payload={id:this.form.slug,name:this.form.name.trim(),repo:this.form.repo.trim(),branch:this.form.branch.trim(),artifactName:this.form.artifactName.trim()||'digiops-release',healthPath:this.form.healthPath.trim()||'/',retention:Number(this.form.retention)||5}
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
    async loadGithubInfo(){
      if(!this.selected)return
      try{this.githubInfo=await api('./api/github.php?project='+encodeURIComponent(this.selected.id));await this.loadProjects()}
      catch(e){this.githubInfo={error:e.message}}
      icons()
    },
    async loadReleases(){
      if(!this.selected)return
      try{const d=await api('./api/releases.php?project='+encodeURIComponent(this.selected.id));this.releases=d.releases||[]}catch(e){this.error=e.message}
      icons()
    },
    async deploy(){
      if(!this.selected)return
      if(!confirm('Deploy latest successful verified artifact to '+this.selected.url+'?'))return
      this.clearMessages();this.busy=true
      try{
        const d=await api('./api/deploy.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':this.csrf},body:JSON.stringify({project:this.selected.id})})
        this.notice='Deployed release '+d.release;await this.loadProjects();await this.loadReleases();await this.checkHealth()
      }catch(e){this.error=e.message}
      finally{this.busy=false;icons()}
    },
    async rollback(release){
      if(!confirm('Rollback '+this.selected.name+' to '+release+'?'))return
      this.clearMessages();this.busy=true
      try{
        await api('./api/rollback.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':this.csrf},body:JSON.stringify({project:this.selected.id,release})})
        this.notice='Rollback complete.';await this.loadProjects();await this.loadReleases();await this.checkHealth()
      }catch(e){this.error=e.message}
      finally{this.busy=false;icons()}
    },
    async checkHealth(){
      if(!this.selected)return
      this.clearMessages();this.busy=true
      try{this.health=await api('./api/health.php?project='+encodeURIComponent(this.selected.id));await this.loadProjects();this.notice=this.health.ok?'Health check passed.':'Health check needs attention.'}
      catch(e){this.error=e.message}
      finally{this.busy=false;icons()}
    },
    async browse(scope='public',path=''){
      if(!this.selected)return
      this.clearMessages()
      try{
        const d=await api('./api/files.php?project='+encodeURIComponent(this.selected.id)+'&scope='+encodeURIComponent(scope)+'&path='+encodeURIComponent(path))
        this.fileListing={scope,...d.listing}
      }catch(e){this.error=e.message}
      icons()
    },
    async loadAudit(){
      if(this.user?.role!=='admin')return
      try{const d=await api('./api/audit.php?limit=150');this.audit=d.events||[]}catch(e){this.error=e.message}
      icons()
    },
    setTab(tab){
      this.projectTab=tab
      if(tab==='releases')this.loadReleases()
      if(tab==='files')this.browse('public','')
      if(tab==='health')this.checkHealth()
      icons()
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
      <div class="mb-7 flex items-center gap-3 px-2"><div class="brand-mark"><i data-lucide="zap"></i></div><div><div class="font-bold">DigiOps</div><div class="text-xs text-slate-500">Stage Operations</div></div><button @click="sidebarOpen=false" class="ml-auto lg:hidden"><i data-lucide="x"></i></button></div>
      <div class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">Workspace</div>
      <button @click="go('dashboard')" class="side-link" :class="page==='dashboard'?'active':''"><i data-lucide="layout-dashboard"></i>Dashboard</button>
      <button @click="go('projects')" class="side-link" :class="['projects','project'].includes(page)?'active':''"><i data-lucide="folder-git-2"></i>Applications<span class="ml-auto rounded-full bg-slate-200 px-2 text-[11px]" x-text="projects.length"></span></button>
      <button @click="go('audit')" class="side-link" :class="page==='audit'?'active':''" x-show="user?.role==='admin'"><i data-lucide="file-clock"></i>Audit Log</button>
      <button @click="go('settings')" class="side-link" :class="page==='settings'?'active':''"><i data-lucide="settings-2"></i>Connections</button>
      <div class="mt-auto rounded-2xl border border-slate-200 bg-white p-3"><div class="flex items-center gap-3"><span class="grid h-9 w-9 place-items-center rounded-full bg-blue-50 text-blue-700"><i data-lucide="user-round" class="h-4 w-4"></i></span><div class="min-w-0 flex-1"><b class="block truncate text-sm" x-text="user?.name"></b><small class="block truncate text-slate-500" x-text="user?.role"></small></div><button @click="logout()" class="icon-btn h-8 w-8 border-0 shadow-none" title="Logout"><i data-lucide="log-out" class="h-4 w-4"></i></button></div></div>
    </aside>

    <main class="min-w-0 flex-1">
      <header class="topbar"><div class="flex min-w-0 flex-1 items-center gap-3"><button @click="sidebarOpen=true" class="icon-btn lg:hidden"><i data-lucide="menu"></i></button><label class="search-field"><i data-lucide="search" class="h-4 w-4 text-slate-400"></i><input x-model="query" placeholder="Search applications, repositories, paths…"></label></div><span class="ml-3 hidden items-center gap-2 rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 sm:inline-flex"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>Secure session</span></header>

      <div class="px-4 py-6 md:px-7">
        <div x-show="notice" class="mb-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700" x-text="notice"></div>
        <div x-show="error" class="mb-4 rounded-xl bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700" x-text="error"></div>

        <section x-show="page==='dashboard'">
          <div class="mb-6 flex flex-wrap items-end justify-between gap-4"><div><p class="text-sm font-medium text-blue-600">Operations overview</p><h1 class="mt-1 text-2xl font-bold">Dashboard</h1><p class="muted mt-1">One control plane for staged applications.</p></div><button @click="openCreate()" class="btn btn-primary"><i data-lucide="plus" class="h-4 w-4"></i>Add application</button></div>
          <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="stat-card"><span class="muted">Applications</span><div class="mt-3 text-3xl font-bold" x-text="stats.total"></div></div>
            <div class="stat-card"><span class="muted">Updates</span><div class="mt-3 text-3xl font-bold" x-text="stats.updates"></div></div>
            <div class="stat-card"><span class="muted">Healthy</span><div class="mt-3 text-3xl font-bold" x-text="stats.healthy"></div></div>
            <div class="stat-card"><span class="muted">Attention</span><div class="mt-3 text-3xl font-bold" x-text="stats.attention"></div></div>
          </div>
          <div class="mt-6 panel"><div class="mb-3 flex justify-between"><div><h2 class="font-bold">Applications</h2><p class="muted">Latest deployment state.</p></div><button @click="go('projects')" class="btn">View all</button></div><template x-for="p in projects.slice(0,6)" :key="p.id"><button @click="openProject(p.id)" class="row w-full text-left"><span><b x-text="p.name"></b><small class="block text-slate-500" x-text="p.repo"></small></span><span class="pill"><span class="status-dot" :class="p.health==='healthy'?'bg-emerald-500':p.health==='attention'?'bg-rose-500':'bg-amber-500'"></span><span x-text="p.health"></span></span></button></template></div>
        </section>

        <section x-show="page==='projects'">
          <div class="mb-6 flex flex-wrap items-end justify-between gap-4"><div><p class="text-sm font-medium text-blue-600">Application registry</p><h1 class="mt-1 text-2xl font-bold">Applications</h1><p class="muted mt-1">Independent GitHub projects with isolated Cloudways paths.</p></div><button @click="openCreate()" class="btn btn-primary"><i data-lucide="plus" class="h-4 w-4"></i>Create application</button></div>
          <div class="mb-5 flex flex-wrap gap-2 border-b border-slate-200 pb-4"><button @click="filter='all'" class="pill" :class="filter==='all'?'border-blue-200 bg-blue-50 text-blue-700':''">All <span x-text="stats.total"></span></button><button @click="filter='updates'" class="pill">Updates <span x-text="stats.updates"></span></button><button @click="filter='healthy'" class="pill">Healthy <span x-text="stats.healthy"></span></button><button @click="filter='attention'" class="pill">Attention <span x-text="stats.attention"></span></button></div>
          <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"><template x-for="p in filteredProjects" :key="p.id"><button @click="openProject(p.id)" class="project-card"><div class="flex items-start justify-between"><div class="flex min-w-0 items-center gap-3"><span class="grid h-11 w-11 place-items-center rounded-2xl bg-blue-50 text-blue-700"><i data-lucide="folder-git-2"></i></span><span class="min-w-0"><b class="block truncate" x-text="p.name"></b><small class="block truncate text-slate-500" x-text="p.repo"></small></span></div><i data-lucide="more-vertical" class="h-5 w-5 text-slate-400"></i></div><div class="mt-5 flex flex-wrap gap-2"><span class="pill"><i data-lucide="git-branch" class="h-3.5 w-3.5"></i><span x-text="p.branch"></span></span><span class="pill"><span class="status-dot" :class="p.health==='healthy'?'bg-emerald-500':p.health==='attention'?'bg-rose-500':'bg-amber-500'"></span><span x-text="p.health"></span></span><span x-show="p.update" class="pill border-amber-200 bg-amber-50 text-amber-700">Update available</span></div><div class="mt-auto grid grid-cols-2 gap-3 pt-6 text-xs"><div><span class="text-slate-400">URL</span><b class="mt-1 block" x-text="p.url"></b></div><div><span class="text-slate-400">Release</span><b class="mt-1 block truncate" x-text="p.release"></b></div></div></button></template></div>
        </section>

        <section x-show="page==='project' && selected">
          <div class="mb-5 flex flex-wrap items-center gap-3"><button @click="go('projects')" class="icon-btn"><i data-lucide="arrow-left"></i></button><div><h1 class="text-xl font-bold" x-text="selected?.name"></h1><p class="text-sm text-slate-500" x-text="selected?.repo"></p></div><span class="pill"><i data-lucide="git-branch" class="h-3.5 w-3.5"></i><span x-text="selected?.branch"></span></span><div class="ml-auto flex gap-2"><button @click="loadGithubInfo()" class="btn" :disabled="busy"><i data-lucide="refresh-cw" class="h-4 w-4"></i>Check update</button><button @click="deploy()" class="btn btn-primary" :disabled="busy"><i data-lucide="rocket" class="h-4 w-4"></i>Deploy</button></div></div>
          <div class="mb-5 flex gap-6 overflow-x-auto border-b border-slate-200"><template x-for="t in ['overview','deploy','releases','files','health','settings']"><button @click="setTab(t)" class="tab capitalize" :class="projectTab===t?'active':''" x-text="t"></button></template></div>
          <div x-show="projectTab==='overview'" class="grid gap-5 xl:grid-cols-[1.4fr_.8fr]">
            <div class="panel"><h2 class="font-bold">Deployment configuration</h2><div class="row"><span><b class="block text-sm">Public URL</b><small class="text-slate-500">Browser route</small></span><code x-text="selected?.url"></code></div><div class="row"><span><b class="block text-sm">Public folder</b><small class="text-slate-500">Release payload only</small></span><code class="text-xs" x-text="selected?.publicPath"></code></div><div class="row"><span><b class="block text-sm">Private folder</b><small class="text-slate-500">Runtime and metadata</small></span><code class="text-xs" x-text="selected?.privatePath"></code></div><div class="row"><span><b class="block text-sm">Current commit</b></span><code x-text="selected?.commit"></code></div></div>
            <div class="space-y-5"><div class="panel"><h2 class="font-bold">GitHub</h2><p class="muted mt-1" x-show="!githubInfo">Checking…</p><div x-show="githubInfo?.connected"><div class="mt-4 flex items-center gap-2 text-sm"><i data-lucide="check-circle-2" class="h-4 w-4 text-emerald-600"></i>Connected</div><div class="mt-4 text-sm"><span class="text-slate-500">Latest workflow</span><b class="mt-1 block" x-text="githubInfo?.runs?.[0]?.conclusion || githubInfo?.runs?.[0]?.status || 'None'"></b></div></div><p x-show="githubInfo?.error" class="mt-3 text-sm text-rose-600" x-text="githubInfo?.error"></p></div><div class="panel"><h2 class="font-bold">Update</h2><p class="muted mt-2" x-text="githubInfo?.updateAvailable?'New commit available.':'No newer commit detected.'"></p></div></div>
          </div>
          <div x-show="projectTab==='deploy'" class="panel"><h2 class="text-xl font-bold">Approval deployment</h2><p class="muted mt-2">DigiOps selects the latest successful GitHub Actions run, downloads the configured artifact, validates ZIP paths and entrypoint, snapshots the current release, then publishes the verified payload.</p><div class="mt-5 flex flex-wrap gap-2"><span class="pill">Artifact: <b x-text="selected?.artifactName"></b></span><span class="pill">Retention: <b x-text="selected?.retention"></b></span></div><button @click="deploy()" class="btn btn-primary mt-6" :disabled="busy"><i data-lucide="rocket" class="h-4 w-4"></i>Deploy latest successful artifact</button></div>
          <div x-show="projectTab==='releases'" class="table-wrap"><div class="table-head"><span>Release</span><span>Commit</span><span>Created</span><span>Action</span></div><template x-for="r in releases" :key="r.id"><div class="table-row"><span class="font-medium" x-text="r.id"></span><code x-text="r.commit || 'snapshot'"></code><span x-text="r.createdAt"></span><span><button @click="rollback(r.id)" class="btn py-1.5 text-xs" :disabled="busy">Rollback</button></span></div></template><div x-show="releases.length===0" class="p-6 text-sm text-slate-500">No releases yet.</div></div>
          <div x-show="projectTab==='files'" class="panel"><div class="mb-4 flex gap-2"><button @click="browse('public','')" class="btn">Public</button><button @click="browse('private','')" class="btn">Private</button></div><div class="mb-3 font-mono text-xs text-slate-500" x-text="fileListing?.scope + ':/' + (fileListing?.path||'')"></div><div class="divide-y divide-slate-100"><template x-for="f in fileListing?.items || []" :key="f.name"><div class="flex items-center justify-between py-3 text-sm"><span class="flex items-center gap-2"><i data-lucide="file-text" class="h-4 w-4 text-slate-400"></i><span x-text="f.name"></span></span><span class="text-xs text-slate-400" x-text="f.type==='dir'?'Folder':f.size+' B'"></span></div></template></div></div>
          <div x-show="projectTab==='health'" class="grid gap-4 md:grid-cols-3"><div class="stat-card"><i data-lucide="heart-pulse" class="h-5 w-5 text-emerald-600"></i><h3 class="mt-3 font-bold">HTTP</h3><p class="muted mt-1" x-text="health?.http?.status ? 'HTTP '+health.http.status+' · '+health.http.ms+'ms' : 'Not checked'"></p></div><div class="stat-card"><i data-lucide="hard-drive" class="h-5 w-5 text-blue-600"></i><h3 class="mt-3 font-bold">Storage</h3><p class="muted mt-1" x-text="health?.storage?.exists ? health.storage.bytes+' bytes' : 'Not deployed'"></p></div><div class="stat-card"><i data-lucide="server" class="h-5 w-5 text-violet-600"></i><h3 class="mt-3 font-bold">Runtime</h3><p class="muted mt-1" x-text="health?.runtime?.php ? 'PHP '+health.runtime.php : 'Not checked'"></p></div></div>
          <div x-show="projectTab==='settings'" class="panel"><h2 class="font-bold">Application settings</h2><div class="mt-5 grid gap-4 md:grid-cols-2"><div><span class="muted">Repository</span><b class="mt-1 block" x-text="selected?.repo"></b></div><div><span class="muted">Branch</span><b class="mt-1 block" x-text="selected?.branch"></b></div><div><span class="muted">Artifact</span><b class="mt-1 block" x-text="selected?.artifactName"></b></div><div><span class="muted">Health path</span><b class="mt-1 block" x-text="selected?.healthPath"></b></div></div></div>
        </section>

        <section x-show="page==='settings'">
          <div class="max-w-2xl panel"><p class="text-sm font-medium text-blue-600">Connection</p><h1 class="mt-1 text-xl font-bold">GitHub access</h1><p class="muted mt-2">Use a fine-grained token with read access to required repositories, Actions and artifacts. The token is encrypted in private_html and is never returned to the browser.</p><form @submit.prevent="connectGithub()" class="mt-5 space-y-4"><label class="block text-sm"><span class="mb-1.5 block font-semibold">GitHub token</span><input x-model="github.token" type="password" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" required></label><label class="block text-sm"><span class="mb-1.5 block font-semibold">Test repository</span><input x-model="github.testRepo" class="w-full rounded-xl border border-slate-200 px-3 py-2.5"></label><button class="btn btn-primary" :disabled="busy"><i data-lucide="github" class="h-4 w-4"></i>Connect GitHub</button></form></div>
        </section>

        <section x-show="page==='audit'">
          <div class="panel"><div class="mb-4 flex justify-between"><div><h1 class="text-xl font-bold">Audit Log</h1><p class="muted">Hash-chained operational events.</p></div><button @click="loadAudit()" class="btn"><i data-lucide="refresh-cw" class="h-4 w-4"></i>Refresh</button></div><div class="divide-y divide-slate-100"><template x-for="a in audit" :key="a.hash"><div class="py-3 text-sm"><div class="flex flex-wrap justify-between gap-2"><b x-text="a.event"></b><span class="text-xs text-slate-400" x-text="a.time"></span></div><div class="mt-1 text-xs text-slate-500"><span x-text="a.actor"></span> · <code x-text="a.hash?.slice(0,12)"></code></div></div></template></div></div>
        </section>
      </div>
    </main>
  </div>

  <div x-show="modal==='create'" class="modal-backdrop"><div class="modal" @click.outside="modal=null"><div class="flex items-center justify-between border-b border-slate-200 px-6 py-5"><div><h2 class="text-lg font-bold">Create application</h2><p class="muted">Map a repository to isolated Cloudways folders.</p></div><button @click="modal=null" class="icon-btn"><i data-lucide="x"></i></button></div><form @submit.prevent="saveProject()" class="space-y-4 p-6"><div class="grid gap-4 md:grid-cols-2"><label class="text-sm"><span class="mb-1.5 block font-semibold">Application name</span><input id="digiops-app-name" x-model="form.name" @input="slugify()" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" required></label><label class="text-sm"><span class="mb-1.5 block font-semibold">Slug</span><input id="digiops-app-slug" x-model="form.slug" @input="syncSlug($event.target.value)" autocomplete="off" spellcheck="false" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" required></label></div><label class="block text-sm"><span class="mb-1.5 block font-semibold">Repository</span><input x-model="form.repo" placeholder="owner/repository" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" required></label><div class="grid gap-4 md:grid-cols-2"><label class="text-sm"><span class="mb-1.5 block font-semibold">Branch</span><input x-model="form.branch" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" required></label><label class="text-sm"><span class="mb-1.5 block font-semibold">Artifact name</span><input x-model="form.artifactName" class="w-full rounded-xl border border-slate-200 px-3 py-2.5"></label></div><div class="grid gap-4 md:grid-cols-2"><label class="text-sm"><span class="mb-1.5 block font-semibold">Health path</span><input x-model="form.healthPath" class="w-full rounded-xl border border-slate-200 px-3 py-2.5"></label><label class="text-sm"><span class="mb-1.5 block font-semibold">Keep releases</span><input x-model="form.retention" type="number" min="1" max="20" class="w-full rounded-xl border border-slate-200 px-3 py-2.5"></label></div><div class="rounded-2xl bg-slate-50 p-4 text-sm"><b>Automatic paths</b><div id="digiops-public-path-preview" class="mt-2 font-mono text-xs text-slate-500">public_html/{slug}/</div><div id="digiops-private-path-preview" class="font-mono text-xs text-slate-500">private_html/{slug}/</div></div><div class="flex justify-end gap-2"><button type="button" @click="modal=null" class="btn">Cancel</button><button class="btn btn-primary" :disabled="busy">Create application</button></div></form></div></div>
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
