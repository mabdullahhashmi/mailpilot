<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sign In — MailPilot</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: {
                        brand: { 400: '#818cf8', 500: '#6366f1', 600: '#4f46e5', 700: '#4338ca' },
                        surface: { 800: '#1e1e2e', 850: '#181825', 900: '#11111b', 950: '#0a0a14' }
                    }
                }
            }
        }
    </script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        .glass { background: rgba(30, 30, 46, 0.6); backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,0.06); }
        .gradient-brand { background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a78bfa 100%); }
        .input-dark { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); transition: all 0.2s; color: #e4e4e7; }
        .input-dark:focus { background: rgba(255,255,255,0.08); border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.15); outline: none; }
        .input-dark::placeholder { color: #71717a; }
        .btn-primary { background: linear-gradient(135deg, #6366f1, #4f46e5); transition: all 0.2s; }
        .btn-primary:hover { background: linear-gradient(135deg, #818cf8, #6366f1); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(99,102,241,0.4); }
        .fade-in { animation: fadeIn 0.6s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
        .float { animation: float 6s ease-in-out infinite; }
        @keyframes float { 0%, 100% { transform: translateY(0px); } 50% { transform: translateY(-8px); } }
    </style>
</head>
<body class="h-full bg-surface-950 font-sans antialiased flex items-center justify-center relative overflow-hidden">

    <!-- Background decoration -->
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
        <div class="absolute -top-40 -right-40 w-96 h-96 rounded-full bg-brand-500/5 blur-3xl float"></div>
        <div class="absolute -bottom-40 -left-40 w-96 h-96 rounded-full bg-purple-500/5 blur-3xl float" style="animation-delay: -3s;"></div>
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] rounded-full bg-brand-600/3 blur-3xl"></div>
    </div>

    <div class="relative z-10 w-full max-w-md px-4 fade-in">
        <!-- Logo -->
        <div class="text-center mb-8">
            <div class="w-16 h-16 rounded-2xl gradient-brand flex items-center justify-center mx-auto shadow-2xl shadow-brand-500/20 mb-4">
                <svg class="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
            </div>
            <h1 class="text-white text-2xl font-bold tracking-tight">MailPilot</h1>
            <p class="text-zinc-500 text-sm mt-1">Sign in to your warmup engine</p>
        </div>

        <!-- Login Card -->
        <div class="glass rounded-2xl p-8">
            @if ($errors->any())
                <div class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/20">
                    <div class="flex items-center gap-2 text-red-400 text-sm">
                        <i data-lucide="alert-circle" class="w-4 h-4 flex-shrink-0"></i>
                        <span>{{ $errors->first() }}</span>
                    </div>
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}">
                @csrf

                <div class="space-y-5">
                    <!-- Email -->
                    <div>
                        <label class="block text-zinc-400 text-xs font-semibold tracking-wider uppercase mb-2">Email Address</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                                <i data-lucide="mail" class="w-4 h-4 text-zinc-600"></i>
                            </div>
                            <input type="email" name="email" value="{{ old('email') }}" required autofocus
                                   class="input-dark w-full pl-10 pr-4 py-3 rounded-xl text-sm"
                                   placeholder="admin@example.com">
                        </div>
                    </div>

                    <!-- Password -->
                    <div>
                        <label class="block text-zinc-400 text-xs font-semibold tracking-wider uppercase mb-2">Password</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                                <i data-lucide="lock" class="w-4 h-4 text-zinc-600"></i>
                            </div>
                            <input type="password" name="password" required
                                   class="input-dark w-full pl-10 pr-4 py-3 rounded-xl text-sm"
                                   placeholder="••••••••">
                        </div>
                    </div>

                    <!-- Remember Me -->
                    <div class="flex items-center justify-between">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="remember" class="w-4 h-4 rounded border-zinc-700 bg-white/5 text-brand-500 focus:ring-brand-500 focus:ring-offset-0">
                            <span class="text-zinc-400 text-sm">Remember me</span>
                        </label>
                    </div>

                    <!-- Submit -->
                    <button type="submit" class="btn-primary w-full py-3 rounded-xl text-white text-sm font-semibold flex items-center justify-center gap-2">
                        <i data-lucide="log-in" class="w-4 h-4"></i>
                        Sign In
                    </button>
                </div>
            </form>
        </div>

        <!-- Footer -->
        <p class="text-center text-zinc-700 text-xs mt-6">&copy; {{ date('Y') }} MailPilot — Warmup Engine</p>
    </div>

    <script>document.addEventListener('DOMContentLoaded', () => lucide.createIcons());</script>
</body>
</html>
