import Alpine from 'alpinejs'
import {
  Activity, AppWindow, ArrowLeft, Boxes, CheckCircle2, ChevronDown, CircleGauge,
  Cloud, Code2, FileClock, FileText, FolderGit2, GitBranch, Github, Globe2,
  HardDrive, HeartPulse, History, LayoutDashboard, ListFilter, LockKeyhole,
  Menu, MoreVertical, PackageCheck, Plus, RefreshCw, Rocket, Search, Server,
  Settings2, ShieldCheck, TerminalSquare, Users, X, Zap
} from 'lucide'
import './styles.css'

window.Alpine = Alpine

const ICONS = {
  Activity, AppWindow, ArrowLeft, Boxes, CheckCircle2, ChevronDown, CircleGauge,
  Cloud, Code2, FileClock, FileText, FolderGit2, GitBranch, Github, Globe2,
  HardDrive, HeartPulse, History, LayoutDashboard, ListFilter, LockKeyhole,
  Menu, MoreVertical, PackageCheck, Plus, RefreshCw, Rocket, Search, Server,
  Settings2, ShieldCheck, TerminalSquare, Users, X, Zap
}
const refreshIcons = () => queueMicrotask(() => createIconsSafe())
function createIconsSafe() {
  import('lucide').then(({ createIcons }) => createIcons({ icons: ICONS })).catch(() => {})
}

const seedProjects = [
  {
    id: 'sahakarxv',
    name: 'SahakarXV',
    repo: 'indigiti/SahakarXV',
    branch: 'main',
    url: '/sahakarxv/',
    publicPath: 'public_html/sahakarxv/',
    privatePath: 'private_html/sahakarxv/',
    status: 'ready',
    health: 'healthy',
    update: true,
    commit: '89fe1f1',
    release: 'Not deployed',
    lastDeploy: 'Pending first deploy',
    stack: ['Vite', 'PHP', 'Python'],
    environment: 'Stage'
  }
]

function app() {
  return {
    sidebarOpen: false,
    page: 'projects',
    projectTab: 'overview',
    query: '',
    filter: 'all',
    modal: null,
    selectedId: null,
    loading: false,
    projects: [],
    stats: { total: 0, updates: 0, healthy: 0, attention: 0 },
    newProject: {
      name: '', repo: '', branch: 'main', slug: '', publicPath: '', privatePath: ''
    },

    async init() {
      await this.loadProjects()
      refreshIcons()
    },

    async loadProjects() {
      this.loading = true
      try {
        const res = await fetch('./api/projects.php', { cache: 'no-store', credentials: 'same-origin' })
        if (!res.ok) throw new Error('API unavailable')
        const data = await res.json()
        this.projects = Array.isArray(data.projects) && data.projects.length ? data.projects : seedProjects
      } catch {
        this.projects = seedProjects
      } finally {
        this.loading = false
        this.recalc()
        refreshIcons()
      }
    },

    recalc() {
      this.stats.total = this.projects.length
      this.stats.updates = this.projects.filter(p => p.update).length
      this.stats.healthy = this.projects.filter(p => p.health === 'healthy').length
      this.stats.attention = this.projects.filter(p => p.health !== 'healthy').length
    },

    get filteredProjects() {
      const q = this.query.trim().toLowerCase()
      return this.projects.filter(p => {
        const matchesQuery = !q || [p.name, p.repo, p.url, p.branch].some(v => String(v || '').toLowerCase().includes(q))
        const matchesFilter = this.filter === 'all'
          || (this.filter === 'updates' && p.update)
          || (this.filter === 'healthy' && p.health === 'healthy')
          || (this.filter === 'attention' && p.health !== 'healthy')
        return matchesQuery && matchesFilter
      })
    },

    get selected() {
      return this.projects.find(p => p.id === this.selectedId) || null
    },

    openProject(id) {
      this.selectedId = id
      this.projectTab = 'overview'
      this.page = 'project'
      this.sidebarOpen = false
      refreshIcons()
    },

    go(page) {
      this.page = page
      this.sidebarOpen = false
      refreshIcons()
    },

    openCreate() {
      this.newProject = {
        name: '', repo: '', branch: 'main', slug: '', publicPath: '', privatePath: ''
      }
      this.modal = 'create'
      refreshIcons()
    },

    slugify() {
      const slug = this.newProject.name
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '')
      this.newProject.slug = slug
      this.newProject.publicPath = slug ? `public_html/${slug}/` : ''
      this.newProject.privatePath = slug ? `private_html/${slug}/` : ''
    },

    addLocalProject() {
      if (!this.newProject.name || !this.newProject.repo || !this.newProject.slug) return
      this.projects.unshift({
        id: this.newProject.slug,
        name: this.newProject.name,
        repo: this.newProject.repo,
        branch: this.newProject.branch || 'main',
        url: '/' + this.newProject.slug + '/',
        publicPath: this.newProject.publicPath,
        privatePath: this.newProject.privatePath,
        status: 'configured',
        health: 'pending',
        update: false,
        commit: '—',
        release: 'Not deployed',
        lastDeploy: 'Never',
        stack: ['Auto-detect'],
        environment: 'Stage'
      })
      this.modal = null
      this.recalc()
      refreshIcons()
    },

    setTab(tab) {
      this.projectTab = tab
      refreshIcons()
    }
  }
}

document.querySelector('#app').innerHTML = `
<div x-data="app" x-init="init()" x-cloak class="shell">
  <div class="app-frame flex">
    <div x-show="sidebarOpen" @click="sidebarOpen=false" class="fixed inset-0 z-40 bg-slate-950/20 lg:hidden"></div>

    <aside class="sidebar" :class="sidebarOpen ? 'open' : ''">
      <div class="mb-7 flex items-center gap-3 px-2">
        <div class="brand-mark"><i data-lucide="zap" class="h-5 w-5"></i></div>
        <div class="min-w-0">
          <div class="font-bold tracking-tight">DigiOps</div>
          <div class="text-xs text-slate-500">Stage Operations</div>
        </div>
        <button @click="sidebarOpen=false" class="ml-auto lg:hidden"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>

      <div class="mb-5">
        <div class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">Workspace</div>
        <button @click="go('dashboard')" class="side-link" :class="page==='dashboard'?'active':''"><i data-lucide="layout-dashboard"></i><span>Dashboard</span></button>
        <button @click="go('projects')" class="side-link" :class="['projects','project'].includes(page)?'active':''"><i data-lucide="folder-git-2"></i><span>Applications</span><span class="ml-auto rounded-full bg-slate-200 px-2 text-[11px]" x-text="projects.length"></span></button>
        <button @click="go('deployments')" class="side-link" :class="page==='deployments'?'active':''"><i data-lucide="rocket"></i><span>Deployments</span></button>
        <button @click="go('releases')" class="side-link" :class="page==='releases'?'active':''"><i data-lucide="history"></i><span>Releases</span></button>
        <button @click="go('health')" class="side-link" :class="page==='health'?'active':''"><i data-lucide="heart-pulse"></i><span>Health</span></button>
      </div>

      <div>
        <div class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">System</div>
        <button @click="go('connections')" class="side-link" :class="page==='connections'?'active':''"><i data-lucide="github"></i><span>Connections</span></button>
        <button @click="go('audit')" class="side-link" :class="page==='audit'?'active':''"><i data-lucide="file-clock"></i><span>Audit Log</span></button>
        <button @click="go('settings')" class="side-link" :class="page==='settings'?'active':''"><i data-lucide="settings-2"></i><span>Settings</span></button>
      </div>

      <div class="mt-auto pt-6">
        <div class="rounded-2xl border border-slate-200 bg-white p-3">
          <div class="flex items-center gap-3">
            <span class="grid h-9 w-9 place-items-center rounded-full bg-blue-50 text-xs font-bold text-blue-700">DO</span>
            <div class="min-w-0 flex-1">
              <div class="truncate text-sm font-semibold">Administrator</div>
              <div class="truncate text-xs text-slate-500">stage.digiti.in</div>
            </div>
            <i data-lucide="chevron-down" class="h-4 w-4 text-slate-400"></i>
          </div>
        </div>
      </div>
    </aside>

    <main class="min-w-0 flex-1">
      <header class="topbar">
        <div class="flex min-w-0 flex-1 items-center gap-3">
          <button @click="sidebarOpen=true" class="icon-btn lg:hidden"><i data-lucide="menu" class="h-5 w-5"></i></button>
          <label class="search-field">
            <i data-lucide="search" class="h-4 w-4 text-slate-400"></i>
            <input x-model="query" placeholder="Search applications, repositories, paths..." />
          </label>
        </div>
        <div class="ml-3 flex items-center gap-2">
          <span class="hidden items-center gap-2 rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 md:inline-flex"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>System ready</span>
          <button class="icon-btn"><i data-lucide="activity" class="h-4 w-4"></i></button>
        </div>
      </header>

      <div class="px-4 py-6 md:px-7 md:py-7">
        <section x-show="page==='dashboard'">
          <div class="mb-6 flex items-end justify-between gap-4">
            <div><p class="text-sm font-medium text-blue-600">Operations overview</p><h1 class="mt-1 text-2xl font-bold tracking-tight">Dashboard</h1><p class="mt-1 muted">One control plane for every staged application.</p></div>
            <button @click="openCreate()" class="btn btn-primary"><i data-lucide="plus" class="h-4 w-4"></i>Add application</button>
          </div>
          <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="stat-card"><div class="flex items-center justify-between"><span class="muted">Applications</span><i data-lucide="boxes" class="h-4 w-4 text-blue-600"></i></div><div class="mt-3 text-3xl font-bold" x-text="stats.total"></div></div>
            <div class="stat-card"><div class="flex items-center justify-between"><span class="muted">Updates</span><i data-lucide="refresh-cw" class="h-4 w-4 text-amber-600"></i></div><div class="mt-3 text-3xl font-bold" x-text="stats.updates"></div></div>
            <div class="stat-card"><div class="flex items-center justify-between"><span class="muted">Healthy</span><i data-lucide="check-circle-2" class="h-4 w-4 text-emerald-600"></i></div><div class="mt-3 text-3xl font-bold" x-text="stats.healthy"></div></div>
            <div class="stat-card"><div class="flex items-center justify-between"><span class="muted">Attention</span><i data-lucide="heart-pulse" class="h-4 w-4 text-rose-600"></i></div><div class="mt-3 text-3xl font-bold" x-text="stats.attention"></div></div>
          </div>
          <div class="mt-6 panel">
            <div class="mb-4 flex items-center justify-between"><div><h2 class="font-bold">Recent applications</h2><p class="muted">Deployment-ready project registry.</p></div><button @click="go('projects')" class="btn">View all</button></div>
            <div class="space-y-1">
              <template x-for="p in projects.slice(0,5)" :key="p.id">
                <button @click="openProject(p.id)" class="row w-full text-left">
                  <span class="flex min-w-0 items-center gap-3"><span class="grid h-10 w-10 place-items-center rounded-xl bg-blue-50 text-blue-700"><i data-lucide="app-window" class="h-5 w-5"></i></span><span class="min-w-0"><b class="block truncate" x-text="p.name"></b><small class="block truncate text-slate-500" x-text="p.repo"></small></span></span>
                  <span class="pill"><span class="status-dot" :class="p.health==='healthy'?'bg-emerald-500':'bg-amber-500'"></span><span x-text="p.health"></span></span>
                </button>
              </template>
            </div>
          </div>
        </section>

        <section x-show="page==='projects'">
          <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div><p class="text-sm font-medium text-blue-600">Application registry</p><h1 class="mt-1 text-2xl font-bold tracking-tight">Applications</h1><p class="mt-1 muted">Independent Git projects mapped to isolated public and private folders.</p></div>
            <button @click="openCreate()" class="btn btn-primary"><i data-lucide="plus" class="h-4 w-4"></i>Create application</button>
          </div>
          <div class="mb-5 flex flex-wrap items-center gap-2 border-b border-slate-200 pb-4">
            <button @click="filter='all'" class="pill" :class="filter==='all'?'border-blue-200 bg-blue-50 text-blue-700':''">All <span x-text="stats.total"></span></button>
            <button @click="filter='updates'" class="pill" :class="filter==='updates'?'border-blue-200 bg-blue-50 text-blue-700':''">Updates <span x-text="stats.updates"></span></button>
            <button @click="filter='healthy'" class="pill" :class="filter==='healthy'?'border-blue-200 bg-blue-50 text-blue-700':''">Healthy <span x-text="stats.healthy"></span></button>
            <button @click="filter='attention'" class="pill" :class="filter==='attention'?'border-blue-200 bg-blue-50 text-blue-700':''">Needs attention <span x-text="stats.attention"></span></button>
            <button class="btn ml-auto"><i data-lucide="list-filter" class="h-4 w-4"></i>Filter</button>
          </div>
          <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <template x-for="p in filteredProjects" :key="p.id">
              <button @click="openProject(p.id)" class="project-card">
                <div class="flex items-start justify-between gap-3">
                  <div class="flex min-w-0 items-center gap-3"><span class="grid h-11 w-11 place-items-center rounded-2xl bg-blue-50 text-blue-700"><i data-lucide="folder-git-2" class="h-5 w-5"></i></span><span class="min-w-0"><b class="block truncate text-base" x-text="p.name"></b><small class="block truncate text-slate-500" x-text="p.repo"></small></span></div>
                  <i data-lucide="more-vertical" class="h-5 w-5 text-slate-400"></i>
                </div>
                <div class="mt-5 flex flex-wrap gap-2"><span class="pill"><i data-lucide="git-branch" class="h-3.5 w-3.5"></i><span x-text="p.branch"></span></span><span class="pill"><span class="status-dot" :class="p.health==='healthy'?'bg-emerald-500':'bg-amber-500'"></span><span x-text="p.health"></span></span><span x-show="p.update" class="pill border-amber-200 bg-amber-50 text-amber-700">Update available</span></div>
                <div class="mt-5 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-blue-600" :style="'width:'+(p.status==='ready'?100:35)+'%'"></div></div>
                <div class="mt-auto grid grid-cols-2 gap-3 pt-5 text-xs">
                  <div><span class="block text-slate-400">Public URL</span><b class="mt-1 block truncate font-medium text-slate-700" x-text="p.url"></b></div>
                  <div><span class="block text-slate-400">Last deploy</span><b class="mt-1 block truncate font-medium text-slate-700" x-text="p.lastDeploy"></b></div>
                </div>
              </button>
            </template>
          </div>
        </section>

        <section x-show="page==='project' && selected">
          <div class="mb-5 flex flex-col gap-4">
            <div class="flex flex-wrap items-center gap-3">
              <button @click="go('projects')" class="icon-btn"><i data-lucide="arrow-left" class="h-4 w-4"></i></button>
              <div class="min-w-0"><h1 class="truncate text-xl font-bold" x-text="selected?.name"></h1><p class="truncate text-sm text-slate-500" x-text="selected?.repo"></p></div>
              <span class="pill ml-1"><span class="status-dot" :class="selected?.health==='healthy'?'bg-emerald-500':'bg-amber-500'"></span><span x-text="selected?.health"></span></span>
              <span class="pill"><i data-lucide="git-branch" class="h-3.5 w-3.5"></i><span x-text="selected?.branch"></span></span>
              <div class="ml-auto flex gap-2"><button class="btn"><i data-lucide="refresh-cw" class="h-4 w-4"></i>Check update</button><button class="btn btn-primary"><i data-lucide="rocket" class="h-4 w-4"></i>Deploy</button></div>
            </div>
            <div class="flex gap-6 overflow-x-auto border-b border-slate-200">
              <button @click="setTab('overview')" class="tab" :class="projectTab==='overview'?'active':''"><i data-lucide="circle-gauge" class="h-4 w-4"></i>Overview</button>
              <button @click="setTab('deploy')" class="tab" :class="projectTab==='deploy'?'active':''"><i data-lucide="rocket" class="h-4 w-4"></i>Deploy</button>
              <button @click="setTab('releases')" class="tab" :class="projectTab==='releases'?'active':''"><i data-lucide="history" class="h-4 w-4"></i>Releases</button>
              <button @click="setTab('files')" class="tab" :class="projectTab==='files'?'active':''"><i data-lucide="file-text" class="h-4 w-4"></i>Files</button>
              <button @click="setTab('health')" class="tab" :class="projectTab==='health'?'active':''"><i data-lucide="heart-pulse" class="h-4 w-4"></i>Health</button>
              <button @click="setTab('settings')" class="tab" :class="projectTab==='settings'?'active':''"><i data-lucide="settings-2" class="h-4 w-4"></i>Settings</button>
            </div>
          </div>

          <div x-show="projectTab==='overview'" class="grid gap-5 xl:grid-cols-[1.4fr_.8fr]">
            <div class="space-y-5">
              <div class="panel">
                <div class="mb-3 flex items-center justify-between"><div><h2 class="font-bold">Deployment configuration</h2><p class="muted">Cloudways-safe public/private separation.</p></div><span class="pill border-emerald-200 bg-emerald-50 text-emerald-700"><i data-lucide="shield-check" class="h-3.5 w-3.5"></i>Path guarded</span></div>
                <div class="row"><span><b class="block text-sm">Public URL</b><small class="text-slate-500">Browser-accessible application path</small></span><code class="rounded-lg bg-slate-50 px-2.5 py-1.5 text-xs" x-text="selected?.url"></code></div>
                <div class="row"><span><b class="block text-sm">Public folder</b><small class="text-slate-500">Release files only</small></span><code class="max-w-[58%] truncate rounded-lg bg-slate-50 px-2.5 py-1.5 text-xs" x-text="selected?.publicPath"></code></div>
                <div class="row"><span><b class="block text-sm">Private folder</b><small class="text-slate-500">Runtime, credentials and release metadata</small></span><code class="max-w-[58%] truncate rounded-lg bg-slate-50 px-2.5 py-1.5 text-xs" x-text="selected?.privatePath"></code></div>
                <div class="row"><span><b class="block text-sm">Repository branch</b><small class="text-slate-500" x-text="selected?.repo"></small></span><span class="pill"><i data-lucide="git-branch" class="h-3.5 w-3.5"></i><span x-text="selected?.branch"></span></span></div>
              </div>
              <div class="panel">
                <div class="mb-4 flex items-center justify-between"><div><h2 class="font-bold">Release readiness</h2><p class="muted">Approval-oriented deployment gates.</p></div><button class="btn">Run checks</button></div>
                <div class="space-y-3">
                  <div class="flex items-center gap-3 rounded-xl bg-emerald-50 px-4 py-3 text-sm"><i data-lucide="check-circle-2" class="h-5 w-5 text-emerald-600"></i><span class="flex-1"><b>Repository connected</b><small class="block text-emerald-700/70" x-text="selected?.repo"></small></span></div>
                  <div class="flex items-center gap-3 rounded-xl bg-emerald-50 px-4 py-3 text-sm"><i data-lucide="shield-check" class="h-5 w-5 text-emerald-600"></i><span class="flex-1"><b>Folder policy valid</b><small class="block text-emerald-700/70">Public and private roots are isolated.</small></span></div>
                  <div class="flex items-center gap-3 rounded-xl bg-amber-50 px-4 py-3 text-sm"><i data-lucide="package-check" class="h-5 w-5 text-amber-600"></i><span class="flex-1"><b>Build artifact pending</b><small class="block text-amber-700/70">First production artifact has not been approved.</small></span></div>
                </div>
              </div>
            </div>
            <div class="space-y-5">
              <div class="panel"><div class="flex items-center gap-3"><span class="grid h-11 w-11 place-items-center rounded-2xl bg-blue-50 text-blue-700"><i data-lucide="github" class="h-5 w-5"></i></span><div><div class="text-xs text-slate-400">Source</div><b x-text="selected?.repo"></b></div></div><div class="mt-5 space-y-3 text-sm"><div class="flex justify-between"><span class="text-slate-500">Branch</span><b x-text="selected?.branch"></b></div><div class="flex justify-between"><span class="text-slate-500">Commit</span><code x-text="selected?.commit"></code></div><div class="flex justify-between"><span class="text-slate-500">Environment</span><b x-text="selected?.environment"></b></div></div></div>
              <div class="panel"><h2 class="font-bold">Stack</h2><div class="mt-3 flex flex-wrap gap-2"><template x-for="s in selected?.stack || []"><span class="pill" x-text="s"></span></template></div></div>
            </div>
          </div>

          <div x-show="projectTab==='deploy'" class="panel">
            <div class="max-w-3xl"><p class="text-sm font-medium text-blue-600">Approval deployment</p><h2 class="mt-1 text-xl font-bold">Prepare next release</h2><p class="mt-2 muted">DigiOps will fetch a verified build artifact, validate its manifest and target paths, snapshot the current release, then publish only the approved public payload.</p>
              <div class="mt-6 grid gap-3 sm:grid-cols-3"><div class="stat-card"><span class="muted">Branch</span><b class="mt-2 block" x-text="selected?.branch"></b></div><div class="stat-card"><span class="muted">Target</span><b class="mt-2 block truncate" x-text="selected?.url"></b></div><div class="stat-card"><span class="muted">Rollback</span><b class="mt-2 block">Required</b></div></div>
              <button class="btn btn-primary mt-6"><i data-lucide="rocket" class="h-4 w-4"></i>Start pre-deploy checks</button>
            </div>
          </div>

          <div x-show="projectTab==='releases'" class="table-wrap">
            <div class="table-head"><span>Release</span><span>Commit</span><span>Status</span><span>Action</span></div>
            <div class="table-row"><span class="font-medium">No production release yet</span><span class="text-slate-500">—</span><span><span class="pill">Pending</span></span><span><button class="btn btn-ghost">—</button></span></div>
          </div>

          <div x-show="projectTab==='files'" class="panel">
            <div class="mb-5 flex items-center justify-between"><div><h2 class="font-bold">Managed paths</h2><p class="muted">Browser file access will be constrained to each application's assigned roots.</p></div><span class="pill"><i data-lucide="lock-keyhole" class="h-3.5 w-3.5"></i>Restricted</span></div>
            <div class="grid gap-4 md:grid-cols-2"><div class="rounded-2xl border border-slate-200 p-4"><div class="flex items-center gap-2"><i data-lucide="globe-2" class="h-4 w-4 text-blue-600"></i><b>Public</b></div><code class="mt-3 block break-all text-xs text-slate-500" x-text="selected?.publicPath"></code></div><div class="rounded-2xl border border-slate-200 p-4"><div class="flex items-center gap-2"><i data-lucide="lock-keyhole" class="h-4 w-4 text-violet-600"></i><b>Private</b></div><code class="mt-3 block break-all text-xs text-slate-500" x-text="selected?.privatePath"></code></div></div>
          </div>

          <div x-show="projectTab==='health'" class="grid gap-4 md:grid-cols-3">
            <div class="stat-card"><i data-lucide="heart-pulse" class="h-5 w-5 text-emerald-600"></i><h3 class="mt-3 font-bold">Application</h3><p class="mt-1 muted">Health probe foundation ready.</p></div>
            <div class="stat-card"><i data-lucide="hard-drive" class="h-5 w-5 text-blue-600"></i><h3 class="mt-3 font-bold">Storage</h3><p class="mt-1 muted">Disk and release usage planned.</p></div>
            <div class="stat-card"><i data-lucide="server" class="h-5 w-5 text-violet-600"></i><h3 class="mt-3 font-bold">Runtime</h3><p class="mt-1 muted">PHP/runtime probes planned.</p></div>
          </div>

          <div x-show="projectTab==='settings'" class="panel">
            <h2 class="font-bold">Application settings</h2><p class="mt-1 muted">Repository, branch, paths, deployment policy, retention and permissions will be managed here.</p>
            <div class="mt-6 grid gap-4 md:grid-cols-2"><label class="text-sm"><span class="mb-1.5 block font-semibold">Repository</span><input class="w-full rounded-xl border border-slate-200 px-3 py-2.5" :value="selected?.repo"></label><label class="text-sm"><span class="mb-1.5 block font-semibold">Branch</span><input class="w-full rounded-xl border border-slate-200 px-3 py-2.5" :value="selected?.branch"></label></div>
          </div>
        </section>

        <section x-show="['deployments','releases','health','connections','audit','settings'].includes(page)">
          <div class="panel">
            <div class="flex items-start gap-4"><span class="grid h-12 w-12 place-items-center rounded-2xl bg-blue-50 text-blue-700"><i data-lucide="code-2" class="h-5 w-5"></i></span><div><p class="text-sm font-medium text-blue-600">DigiOps v0.1.0</p><h1 class="mt-1 text-xl font-bold capitalize" x-text="page"></h1><p class="mt-2 muted">Foundation screen reserved. This module is included in the phased build plan and will be activated without changing the frozen admin shell.</p></div></div>
          </div>
        </section>
      </div>
    </main>
  </div>

  <div x-show="modal==='create'" class="modal-backdrop" @keydown.escape.window="modal=null">
    <div class="modal" @click.outside="modal=null">
      <div class="flex items-center justify-between border-b border-slate-200 px-6 py-5"><div><h2 class="text-lg font-bold">Create application</h2><p class="muted">Register an isolated Git project and its Cloudways paths.</p></div><button @click="modal=null" class="icon-btn"><i data-lucide="x" class="h-4 w-4"></i></button></div>
      <div class="space-y-5 p-6">
        <div class="grid gap-4 md:grid-cols-2">
          <label class="text-sm"><span class="mb-1.5 block font-semibold">Application name</span><input x-model="newProject.name" @input="slugify()" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" placeholder="SahakarXV"></label>
          <label class="text-sm"><span class="mb-1.5 block font-semibold">Branch</span><input x-model="newProject.branch" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" placeholder="main"></label>
        </div>
        <label class="text-sm"><span class="mb-1.5 block font-semibold">GitHub repository</span><div class="flex items-center rounded-xl border border-slate-200 px-3"><i data-lucide="github" class="h-4 w-4 text-slate-400"></i><input x-model="newProject.repo" class="w-full border-0 px-2 py-2.5 outline-none" placeholder="owner/repository"></div></label>
        <div class="grid gap-4 md:grid-cols-2">
          <label class="text-sm"><span class="mb-1.5 block font-semibold">URL slug</span><input x-model="newProject.slug" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" placeholder="sahakarxv"></label>
          <label class="text-sm"><span class="mb-1.5 block font-semibold">Public URL</span><input class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-slate-500" :value="newProject.slug ? '/'+newProject.slug+'/' : '/'" readonly></label>
        </div>
        <div class="rounded-2xl bg-slate-50 p-4 text-sm"><div class="flex items-start gap-3"><i data-lucide="shield-check" class="mt-0.5 h-5 w-5 text-emerald-600"></i><div><b>Cloudways folder isolation</b><p class="mt-1 text-slate-500">Public releases and private runtime data are registered separately. Arbitrary paths outside approved roots will be rejected.</p><div class="mt-3 space-y-1 font-mono text-xs text-slate-600"><div x-text="newProject.publicPath || 'public_html/{slug}/'"></div><div x-text="newProject.privatePath || 'private_html/{slug}/'"></div></div></div></div></div>
      </div>
      <div class="flex justify-end gap-2 border-t border-slate-200 px-6 py-4"><button @click="modal=null" class="btn">Cancel</button><button @click="addLocalProject()" class="btn btn-primary" :disabled="!newProject.name || !newProject.repo || !newProject.slug">Create application</button></div>
    </div>
  </div>
</div>
`

Alpine.data('app', app)
Alpine.start()
refreshIcons()
