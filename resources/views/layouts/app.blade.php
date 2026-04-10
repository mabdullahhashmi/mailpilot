<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') — MailPilot</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: {
                        brand: {
                            50: '#eef2ff', 100: '#e0e7ff', 200: '#c7d2fe', 300: '#a5b4fc',
                            400: '#818cf8', 500: '#6366f1', 600: '#4f46e5', 700: '#4338ca',
                            800: '#3730a3', 900: '#312e81', 950: '#1e1b4b'
                        },
                        surface: {
                            50: '#fafafa', 100: '#f4f4f5', 200: '#e4e4e7', 300: '#d4d4d8',
                            800: '#1e1e2e', 850: '#181825', 900: '#11111b', 950: '#0a0a14'
                        }
                    }
                }
            }
        }
    </script>

    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <style>
        [x-cloak] { display: none !important; }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #3f3f46; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #52525b; }

        .glass { background: rgba(30, 30, 46, 0.6); backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,0.06); }
        .glass-light { background: rgba(255,255,255,0.03); backdrop-filter: blur(8px); }

        .gradient-brand { background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a78bfa 100%); }
        .gradient-success { background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); }
        .gradient-warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .gradient-danger { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        .gradient-info { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }

        .stat-card { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 20px 40px -12px rgba(0,0,0,0.3); }

        .nav-item { transition: all 0.2s ease; position: relative; }
        .nav-item.active { background: rgba(99, 102, 241, 0.15); color: #a5b4fc; }
        .nav-item.active::before { content: ''; position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 3px; height: 60%; background: #6366f1; border-radius: 0 4px 4px 0; }
        .nav-item:hover:not(.active) { background: rgba(255,255,255,0.05); color: #e4e4e7; }

        .pulse-dot { animation: pulse-dot 2s infinite; }
        @keyframes pulse-dot { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }

        .fade-in { animation: fadeIn 0.4s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }

        .shimmer { background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.04) 50%, transparent 100%); background-size: 200% 100%; animation: shimmer 2s infinite; }
        @keyframes shimmer { 0% { background-position: -200% 0; } 100% { background-position: 200% 0; } }

        .table-row { transition: background 0.15s ease; }
        .table-row:hover { background: rgba(255,255,255,0.03); }

        .btn-primary { background: linear-gradient(135deg, #6366f1, #4f46e5); transition: all 0.2s; }
        .btn-primary:hover { background: linear-gradient(135deg, #818cf8, #6366f1); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(99,102,241,0.4); }

        .btn-ghost { transition: all 0.2s; }
        .btn-ghost:hover { background: rgba(255,255,255,0.08); }

        .badge { font-size: 11px; letter-spacing: 0.5px; text-transform: uppercase; font-weight: 600; }

        .modal-overlay { background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); }

        .input-dark { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); transition: all 0.2s; }
        .input-dark:focus { background: rgba(255,255,255,0.08); border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.15); outline: none; }
    </style>
</head>
<body class="h-full bg-surface-950 text-zinc-300 font-sans antialiased" x-data="{ sidebarOpen: true, mobileMenu: false }">

    <div class="flex h-full">
        <!-- Sidebar -->
        <aside class="fixed inset-y-0 left-0 z-40 flex flex-col transition-all duration-300"
               :class="sidebarOpen ? 'w-64' : 'w-20'"
               style="background: linear-gradient(180deg, #1a1a2e 0%, #16162a 50%, #0f0f1a 100%); border-right: 1px solid rgba(255,255,255,0.06);">

            <!-- Logo -->
            <div class="flex items-center h-16 px-4 border-b border-white/5">
                <div class="flex items-center gap-3 overflow-hidden">
                    <div class="w-10 h-10 rounded-xl gradient-brand flex items-center justify-center flex-shrink-0 shadow-lg shadow-brand-500/20">
                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                    </div>
                    <div x-show="sidebarOpen" x-transition class="overflow-hidden">
                        <h1 class="text-white font-bold text-lg tracking-tight">MailPilot</h1>
                        <p class="text-zinc-500 text-[10px] font-medium tracking-wider uppercase">Warmup Engine</p>
                    </div>
                </div>
            </div>

            <!-- Navigation -->
            <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
                <p x-show="sidebarOpen" class="px-3 mb-2 text-[10px] font-semibold tracking-widest uppercase text-zinc-600">Overview</p>

                <a href="{{ route('dashboard') }}" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-zinc-400 {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                    <i data-lucide="layout-dashboard" class="w-5 h-5 flex-shrink-0"></i>
                    <span x-show="sidebarOpen" x-transition>Dashboard</span>
                </a>

                <p x-show="sidebarOpen" class="px-3 mt-6 mb-2 text-[10px] font-semibold tracking-widest uppercase text-zinc-600">Warmup</p>

                <a href="{{ route('dashboard.campaigns') }}" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-zinc-400 {{ request()->routeIs('dashboard.campaigns') ? 'active' : '' }}">
                    <i data-lucide="flame" class="w-5 h-5 flex-shrink-0"></i>
                    <span x-show="sidebarOpen" x-transition>Campaigns</span>
                </a>
                <a href="{{ route('dashboard.profiles') }}" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-zinc-400 {{ request()->routeIs('dashboard.profiles') ? 'active' : '' }}">
                    <i data-lucide="sliders-horizontal" class="w-5 h-5 flex-shrink-0"></i>
                    <span x-show="sidebarOpen" x-transition>Profiles</span>
                </a>

                <p x-show="sidebarOpen" class="px-3 mt-6 mb-2 text-[10px] font-semibold tracking-widest uppercase text-zinc-600">Mailboxes</p>

                <a href="{{ route('dashboard.senders') }}" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-zinc-400 {{ request()->routeIs('dashboard.senders') ? 'active' : '' }}">
                    <i data-lucide="send" class="w-5 h-5 flex-shrink-0"></i>
                    <span x-show="sidebarOpen" x-transition>Senders</span>
                </a>
                <a href="{{ route('dashboard.seeds') }}" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-zinc-400 {{ request()->routeIs('dashboard.seeds') ? 'active' : '' }}">
                    <i data-lucide="inbox" class="w-5 h-5 flex-shrink-0"></i>
                    <span x-show="sidebarOpen" x-transition>Seeds</span>
                </a>
                <a href="{{ route('dashboard.domains') }}" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-zinc-400 {{ request()->routeIs('dashboard.domains') ? 'active' : '' }}">
                    <i data-lucide="globe" class="w-5 h-5 flex-shrink-0"></i>
                    <span x-show="sidebarOpen" x-transition>Domains</span>
                </a>

                <p x-show="sidebarOpen" class="px-3 mt-6 mb-2 text-[10px] font-semibold tracking-widest uppercase text-zinc-600">Monitoring</p>

                <a href="{{ route('dashboard.logs') }}" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-zinc-400 {{ request()->routeIs('dashboard.logs') ? 'active' : '' }}">
                    <i data-lucide="activity" class="w-5 h-5 flex-shrink-0"></i>
                    <span x-show="sidebarOpen" x-transition>Event Logs</span>
                </a>

                <p x-show="sidebarOpen" class="px-3 mt-6 mb-2 text-[10px] font-semibold tracking-widest uppercase text-zinc-600">System</p>

                <a href="{{ route('dashboard.settings') }}" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-zinc-400 {{ request()->routeIs('dashboard.settings') ? 'active' : '' }}">
                    <i data-lucide="settings" class="w-5 h-5 flex-shrink-0"></i>
                    <span x-show="sidebarOpen" x-transition>Settings</span>
                </a>
            </nav>

            <!-- Sidebar Toggle -->
            <div class="p-3 border-t border-white/5">
                <button @click="sidebarOpen = !sidebarOpen" class="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-lg text-zinc-500 hover:text-zinc-300 hover:bg-white/5 transition">
                    <i data-lucide="panel-left-close" class="w-4 h-4" x-show="sidebarOpen"></i>
                    <i data-lucide="panel-left-open" class="w-4 h-4" x-show="!sidebarOpen"></i>
                    <span x-show="sidebarOpen" class="text-xs font-medium">Collapse</span>
                </button>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 transition-all duration-300 overflow-auto" :class="sidebarOpen ? 'ml-64' : 'ml-20'">

            <!-- Top Bar -->
            <header class="sticky top-0 z-30 h-16 flex items-center justify-between px-6 border-b border-white/5" style="background: rgba(10,10,20,0.8); backdrop-filter: blur(12px);">
                <div class="flex items-center gap-4">
                    <button @click="mobileMenu = !mobileMenu" class="lg:hidden text-zinc-400 hover:text-white">
                        <i data-lucide="menu" class="w-5 h-5"></i>
                    </button>
                    <div>
                        <h2 class="text-white font-semibold text-base">@yield('page-title', 'Dashboard')</h2>
                        <p class="text-zinc-500 text-xs">@yield('page-description', 'Welcome to MailPilot warmup engine')</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-emerald-500/10 border border-emerald-500/20">
                        <div class="w-2 h-2 rounded-full bg-emerald-400 pulse-dot"></div>
                        <span class="text-emerald-400 text-xs font-medium">Engine Active</span>
                    </div>
                    <div class="flex items-center gap-2 px-2 py-1 rounded-lg text-zinc-400 text-xs">
                        <i data-lucide="user" class="w-3.5 h-3.5"></i>
                        <span>{{ Auth::user()->name ?? 'Admin' }}</span>
                    </div>
                    <form method="POST" action="{{ route('logout') }}" class="inline">
                        @csrf
                        <button type="submit" class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-zinc-500 hover:text-red-400 hover:bg-red-500/10 transition text-xs font-medium" title="Sign out">
                            <i data-lucide="log-out" class="w-3.5 h-3.5"></i>
                            <span class="hidden sm:inline">Logout</span>
                        </button>
                    </form>
                </div>
            </header>

            <!-- Page Content -->
            <div class="p-6 fade-in">
                @yield('content')
            </div>
        </main>
    </div>

    <script>
        // Initialize Lucide icons
        document.addEventListener('DOMContentLoaded', () => {
            lucide.createIcons();
        });

        // Helper: API fetch with CSRF
        async function apiCall(url, method = 'GET', body = null) {
            const opts = {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }
            };
            if (body) opts.body = JSON.stringify(body);
            const res = await fetch(url, opts);
            if (res.status === 401) {
                window.location.href = '/login';
                throw new Error('Session expired');
            }
            if (res.status === 419) {
                window.location.reload();
                throw new Error('CSRF token mismatch');
            }
            if (res.status === 429) {
                throw new Error('Too many requests. Please wait a moment.');
            }
            if (!res.ok) {
                const text = await res.text();
                let msg = text;
                try { const j = JSON.parse(text); msg = j.error || j.message || text; } catch {}
                throw new Error(msg);
            }
            return res.json();
        }

        // Toast notification
        function showToast(message, type = 'success') {
            const colors = {
                success: 'bg-emerald-500/20 border-emerald-500/30 text-emerald-400',
                error: 'bg-red-500/20 border-red-500/30 text-red-400',
                info: 'bg-blue-500/20 border-blue-500/30 text-blue-400',
                warning: 'bg-amber-500/20 border-amber-500/30 text-amber-400',
            };
            const toast = document.createElement('div');
            toast.className = `fixed bottom-6 right-6 z-50 px-4 py-3 rounded-xl border ${colors[type]} text-sm font-medium shadow-2xl fade-in`;
            toast.textContent = message;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 4000);
        }
    </script>

    @stack('scripts')
</body>
</html>
