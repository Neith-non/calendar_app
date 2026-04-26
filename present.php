<?php
// present.php
session_start();
// NOTE: No backend logic here yet! Just a UI skeleton.
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en" class="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SJSFI - Present Schedule</title>
    
    <script>
        if (localStorage.getItem('color-theme') === 'dark') {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@500;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/styles.css">

    <script>
        tailwind.config = {
            darkMode: 'class', 
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Plus Jakarta Sans', 'sans-serif'],
                        chinese: ['Noto Sans TC', 'sans-serif'],
                    },
                    colors: {
                        sjsfi: {
                            green: '#004731',
                            greenHover: '#003323',
                            light: '#f8faf9',
                            yellow: '#ffbb00'
                        }
                    }
                }
            }
        }
    </script>

    <style>
        body { color: #1e293b; transition: background-color 0.3s ease, color 0.3s ease; }
        .dark body { color: #f1f5f9; }

        .nav-item { color: #64748b; transition: all 0.2s ease; }
        .nav-item:hover { color: #004731; background-color: #f1f5f9; }
        .dark .nav-item { color: #94a3b8; }
        .dark .nav-item:hover { color: #10b981; background-color: rgba(30, 41, 59, 0.5); }
        .nav-item.active { background-color: #004731; color: #ffffff; box-shadow: 0 4px 12px rgba(0, 71, 49, 0.15); }
        .dark .nav-item.active { background-color: #10b981; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2); }

        .bento-card {
            background: #ffffff;
            border: 1px solid #e2e8f0; 
            border-radius: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02);
        }
        .dark .bento-card { background: #111827; border-color: #1e293b; }

        .dark ::-webkit-scrollbar-thumb { background-color: #334155; }
        .dark ::-webkit-scrollbar-track { background-color: #0f172a; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: rgba(0, 0, 0, 0.05); border-radius: 4px; }
        .dark .custom-scrollbar::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.05); }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(0, 0, 0, 0.15); border-radius: 4px; }
        .dark .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.2); }

        /* Custom Checkbox Styling */
        .custom-checkbox:checked { background-color: #004731; border-color: #004731; }
        .dark .custom-checkbox:checked { background-color: #10b981; border-color: #10b981; }
    </style>
</head>

<body x-data="{ 
        isPresenting: false, 
        presentTab: 'table', 
        showQuitModal: false, 
        sidebarOpen: false,
        
        startPresentation() {
            this.isPresenting = true;
            if (document.documentElement.requestFullscreen) {
                document.documentElement.requestFullscreen().catch(err => console.log(err));
            }
        },
        exitPresentation() {
            this.isPresenting = false;
            this.showQuitModal = false;
            if (document.exitFullscreen && document.fullscreenElement) {
                document.exitFullscreen().catch(err => console.log(err));
            }
        }
    }" 
    class="h-screen flex overflow-hidden bg-[#f8faf9] dark:bg-[#030712] transition-colors duration-300">

    <div x-show="!isPresenting" class="flex h-full shrink-0">
        <?php include 'includes/sidebar.php'; ?>
    </div>

    <main class="flex-1 flex flex-col min-w-0 h-full relative custom-scrollbar overflow-y-auto">

        <div x-show="!isPresenting" x-transition.opacity.duration.300ms class="p-6 md:p-8 lg:p-10">
            
            <div class="lg:hidden flex items-center justify-between mb-6 pb-4 border-b border-slate-200 dark:border-slate-800 w-full">
                <h2 class="text-lg font-bold text-slate-800 dark:text-white">Menu</h2>
                <button @click="sidebarOpen = !sidebarOpen" class="w-10 h-10 bg-white dark:bg-[#111827] rounded-xl border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300 flex items-center justify-center shadow-sm hover:text-sjsfi-green dark:hover:text-emerald-400">
                    <i class="fa-solid fa-bars"></i>
                </button>
            </div>

            <div class="mb-8">
                <h1 class="text-3xl font-extrabold tracking-tight text-sjsfi-green dark:text-slate-100 mb-2">Presentation Setup</h1>
                <p class="text-slate-500 dark:text-slate-400 text-sm font-medium">Configure what data will be shown before entering immersive mode.</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                
                <div class="bento-card p-6">
                    <h3 class="text-sm font-extrabold text-slate-800 dark:text-slate-100 mb-4 flex items-center gap-2">
                        <i class="fa-regular fa-calendar text-sjsfi-green dark:text-emerald-500"></i> Select Timeline
                    </h3>
                    <div class="space-y-3">
                        <select class="w-full px-4 py-3 text-sm font-bold border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-sjsfi-green dark:focus:ring-emerald-500 text-slate-800 dark:text-slate-200 cursor-pointer">
                            <option>April 2026</option>
                            <option>May 2026</option>
                            <option>June 2026</option>
                        </select>
                    </div>
                </div>

                <div class="bento-card p-6">
                    <h3 class="text-sm font-extrabold text-slate-800 dark:text-slate-100 mb-4 flex items-center gap-2">
                        <i class="fa-solid fa-filter text-sjsfi-green dark:text-emerald-500"></i> Event Categories
                    </h3>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="flex items-center space-x-3 cursor-pointer group">
                            <input type="checkbox" checked class="custom-checkbox w-5 h-5 rounded border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-800">
                            <span class="text-sm font-bold text-slate-600 dark:text-slate-300 group-hover:text-sjsfi-green dark:group-hover:text-emerald-400 transition">Academic</span>
                        </label>
                        <label class="flex items-center space-x-3 cursor-pointer group">
                            <input type="checkbox" checked class="custom-checkbox w-5 h-5 rounded border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-800">
                            <span class="text-sm font-bold text-slate-600 dark:text-slate-300 group-hover:text-sjsfi-green dark:group-hover:text-emerald-400 transition">Extra-Curricular</span>
                        </label>
                        <label class="flex items-center space-x-3 cursor-pointer group">
                            <input type="checkbox" checked class="custom-checkbox w-5 h-5 rounded border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-800">
                            <span class="text-sm font-bold text-slate-600 dark:text-slate-300 group-hover:text-sjsfi-green dark:group-hover:text-emerald-400 transition">Holidays</span>
                        </label>
                        <label class="flex items-center space-x-3 cursor-pointer group">
                            <input type="checkbox" checked class="custom-checkbox w-5 h-5 rounded border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-800">
                            <span class="text-sm font-bold text-slate-600 dark:text-slate-300 group-hover:text-sjsfi-green dark:group-hover:text-emerald-400 transition">Meetings</span>
                        </label>
                    </div>
                </div>

                <div class="bento-card p-6">
                    <h3 class="text-sm font-extrabold text-slate-800 dark:text-slate-100 mb-4 flex items-center gap-2">
                        <i class="fa-solid fa-table-columns text-sjsfi-green dark:text-emerald-500"></i> Display Columns
                    </h3>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="flex items-center space-x-3 cursor-pointer group opacity-50">
                            <input type="checkbox" checked disabled class="w-5 h-5 rounded border-slate-300 dark:border-slate-600 bg-slate-200 dark:bg-slate-700">
                            <span class="text-sm font-bold text-slate-600 dark:text-slate-300">Event Name</span>
                        </label>
                        <label class="flex items-center space-x-3 cursor-pointer group">
                            <input type="checkbox" checked class="custom-checkbox w-5 h-5 rounded border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-800">
                            <span class="text-sm font-bold text-slate-600 dark:text-slate-300 group-hover:text-sjsfi-green dark:group-hover:text-emerald-400 transition">Date & Time</span>
                        </label>
                        <label class="flex items-center space-x-3 cursor-pointer group">
                            <input type="checkbox" checked class="custom-checkbox w-5 h-5 rounded border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-800">
                            <span class="text-sm font-bold text-slate-600 dark:text-slate-300 group-hover:text-sjsfi-green dark:group-hover:text-emerald-400 transition">Event Details</span>
                        </label>
                        <label class="flex items-center space-x-3 cursor-pointer group">
                            <input type="checkbox" checked class="custom-checkbox w-5 h-5 rounded border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-800">
                            <span class="text-sm font-bold text-slate-600 dark:text-slate-300 group-hover:text-sjsfi-green dark:group-hover:text-emerald-400 transition">Venue</span>
                        </label>
                        <label class="flex items-center space-x-3 cursor-pointer group">
                            <input type="checkbox" checked class="custom-checkbox w-5 h-5 rounded border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-800">
                            <span class="text-sm font-bold text-slate-600 dark:text-slate-300 group-hover:text-sjsfi-green dark:group-hover:text-emerald-400 transition">Participants</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <button @click="startPresentation()" class="bg-sjsfi-green dark:bg-emerald-600 hover:bg-sjsfi-greenHover dark:hover:bg-emerald-500 text-white font-extrabold text-lg py-4 px-10 rounded-2xl transition shadow-xl flex items-center justify-center gap-3 transform hover:scale-105 duration-300">
                    <i class="fa-solid fa-desktop"></i> Start Presentation
                </button>
            </div>
        </div>


        <div x-show="isPresenting" x-transition.opacity.duration.500ms style="display: none;" class="absolute inset-0 z-50 bg-[#f8faf9] dark:bg-[#030712] flex flex-col h-screen w-screen overflow-hidden">
            
            <div class="bg-white dark:bg-[#111827] border-b border-slate-200 dark:border-slate-800 shadow-sm shrink-0">
                <div class="max-w-7xl mx-auto px-6">
                    <div class="flex items-center justify-center gap-8">
                        <button @click="presentTab = 'table'" 
                                :class="presentTab === 'table' ? 'border-sjsfi-green dark:border-emerald-500 text-sjsfi-green dark:text-emerald-400' : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'"
                                class="py-6 px-4 border-b-4 font-black text-xl transition-colors duration-300 flex items-center gap-3 tracking-tight">
                            <i class="fa-solid fa-table-list"></i> Table of Events
                        </button>
                        <button @click="presentTab = 'calendar'" 
                                :class="presentTab === 'calendar' ? 'border-sjsfi-green dark:border-emerald-500 text-sjsfi-green dark:text-emerald-400' : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'"
                                class="py-6 px-4 border-b-4 font-black text-xl transition-colors duration-300 flex items-center gap-3 tracking-tight">
                            <i class="fa-regular fa-calendar-days"></i> Calendar View
                        </button>
                    </div>
                </div>
            </div>

            <div x-show="presentTab === 'table'" x-transition.opacity class="flex-1 overflow-y-auto p-8 lg:p-12">
                <div class="max-w-[1600px] mx-auto bg-white dark:bg-[#111827] rounded-3xl shadow-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-50 dark:bg-[#1e293b] border-b border-slate-200 dark:border-slate-700">
                                <th class="py-5 px-6 text-sm font-black text-slate-500 dark:text-slate-400 uppercase tracking-widest w-[20%]">Event Name</th>
                                <th class="py-5 px-6 text-sm font-black text-slate-500 dark:text-slate-400 uppercase tracking-widest w-[15%]">Date & Time</th>
                                <th class="py-5 px-6 text-sm font-black text-slate-500 dark:text-slate-400 uppercase tracking-widest w-[30%]">Event Details</th>
                                <th class="py-5 px-6 text-sm font-black text-slate-500 dark:text-slate-400 uppercase tracking-widest w-[15%]">Venue</th>
                                <th class="py-5 px-6 text-sm font-black text-slate-500 dark:text-slate-400 uppercase tracking-widest w-[20%]">Participants</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800/50 text-base">
                            
                            <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-colors">
                                <td class="py-6 px-6 align-top">
                                    <h3 class="text-lg font-black text-slate-800 dark:text-white mb-2">Intramurals 2026 Opening</h3>
                                    <span class="bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-500/20 text-xs font-extrabold px-2.5 py-1 rounded-md uppercase tracking-wider">Extra-Curricular</span>
                                </td>
                                <td class="py-6 px-6 align-top">
                                    <div class="flex flex-col gap-2 font-bold text-slate-700 dark:text-slate-300">
                                        <div class="flex items-center gap-2"><i class="fa-regular fa-calendar text-slate-400 w-5"></i> April 15, 2026</div>
                                        <div class="flex items-center gap-2"><i class="fa-regular fa-clock text-slate-400 w-5"></i> 8:00 AM - 5:00 PM</div>
                                    </div>
                                </td>
                                <td class="py-6 px-6 align-top">
                                    <p class="font-medium text-slate-600 dark:text-slate-400 leading-relaxed">Opening ceremonies for the annual intramurals including parade, torch lighting, and the first basketball exhibition matches.</p>
                                </td>
                                <td class="py-6 px-6 align-top">
                                    <div class="font-bold text-slate-800 dark:text-slate-200 flex items-start gap-2">
                                        <i class="fa-solid fa-location-dot text-red-500 mt-1"></i> School Gymnasium
                                    </div>
                                </td>
                                <td class="py-6 px-6 align-top">
                                    <div class="flex flex-col gap-1.5">
                                        <span class="bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 text-sm font-bold px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 inline-block w-max">All Students</span>
                                        <span class="bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 text-sm font-bold px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 inline-block w-max">PE Department Faculty</span>
                                    </div>
                                </td>
                            </tr>
                            
                            <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-colors">
                                <td class="py-6 px-6 align-top">
                                    <h3 class="text-lg font-black text-slate-800 dark:text-white mb-2">Faculty Department Meeting</h3>
                                    <span class="bg-orange-50 dark:bg-orange-500/10 text-orange-700 dark:text-orange-400 border border-orange-200 dark:border-orange-500/20 text-xs font-extrabold px-2.5 py-1 rounded-md uppercase tracking-wider">Meeting</span>
                                </td>
                                <td class="py-6 px-6 align-top">
                                    <div class="flex flex-col gap-2 font-bold text-slate-700 dark:text-slate-300">
                                        <div class="flex items-center gap-2"><i class="fa-regular fa-calendar text-slate-400 w-5"></i> April 18, 2026</div>
                                        <div class="flex items-center gap-2"><i class="fa-regular fa-clock text-slate-400 w-5"></i> 3:30 PM - 5:00 PM</div>
                                    </div>
                                </td>
                                <td class="py-6 px-6 align-top">
                                    <p class="font-medium text-slate-600 dark:text-slate-400 leading-relaxed">Monthly alignment regarding the upcoming examinations and discussion of student extracurricular involvements.</p>
                                </td>
                                <td class="py-6 px-6 align-top">
                                    <div class="font-bold text-slate-800 dark:text-slate-200 flex items-start gap-2">
                                        <i class="fa-solid fa-location-dot text-red-500 mt-1"></i> Audio Visual Room
                                    </div>
                                </td>
                                <td class="py-6 px-6 align-top">
                                    <div class="flex flex-col gap-1.5">
                                        <span class="bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 text-sm font-bold px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 inline-block w-max">Academic Coordinators</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div x-show="presentTab === 'calendar'" x-transition.opacity style="display: none;" class="flex-1 overflow-y-auto p-8 lg:p-12">
                <div class="max-w-[1600px] mx-auto bg-white dark:bg-[#111827] rounded-3xl shadow-xl border border-slate-200 dark:border-slate-800 p-8 min-h-[600px] flex flex-col items-center justify-center text-center">
                    <div class="w-24 h-24 bg-slate-50 dark:bg-slate-800 rounded-full flex items-center justify-center mb-6 border border-slate-200 dark:border-slate-700 shadow-inner">
                        <i class="fa-regular fa-calendar-days text-5xl text-slate-300 dark:text-slate-600"></i>
                    </div>
                    <h2 class="text-2xl font-black text-slate-800 dark:text-white mb-2">Calendar View Canvas</h2>
                    <p class="text-slate-500 dark:text-slate-400 font-medium max-w-md mx-auto">This area is reserved for rendering the visual grid layout once the backend data is piped into the presentation engine.</p>
                </div>
            </div>

            <button @click="showQuitModal = true" class="fixed bottom-8 right-8 z-50 bg-red-600/90 backdrop-blur-md text-white px-6 py-4 rounded-full font-bold shadow-2xl hover:bg-red-700 hover:scale-105 transition-all duration-300 flex items-center gap-3 border border-red-500">
                <i class="fa-solid fa-compress text-lg"></i> Exit Presentation
            </button>
        </div>
        
    </main>

    <div x-show="showQuitModal" style="display: none;" class="fixed inset-0 z-[100] bg-slate-900/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div @click.away="showQuitModal = false" x-show="showQuitModal" x-transition.scale.origin.center class="bg-white dark:bg-[#0b1120] rounded-[2rem] shadow-2xl border border-slate-200 dark:border-slate-700 w-full max-w-md overflow-hidden transform transition-all">
            <div class="p-8 text-center">
                <div class="w-20 h-20 bg-red-50 dark:bg-red-500/10 rounded-full flex items-center justify-center mx-auto mb-6 border border-red-100 dark:border-red-500/20">
                    <i class="fa-solid fa-person-walking-arrow-right text-4xl text-red-500 dark:text-red-400"></i>
                </div>
                <h3 class="text-2xl font-black text-slate-800 dark:text-white mb-2">Exit Presentation?</h3>
                <p class="text-slate-500 dark:text-slate-400 font-medium mb-8">Are you sure you want to exit fullscreen mode and return to the setup dashboard?</p>
                
                <div class="flex gap-4">
                    <button @click="showQuitModal = false" class="flex-1 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 font-extrabold py-4 rounded-xl transition">
                        Cancel
                    </button>
                    <button @click="exitPresentation()" class="flex-1 bg-red-600 hover:bg-red-700 text-white font-extrabold py-4 rounded-xl transition shadow-lg shadow-red-600/20">
                        Yes, Exit
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script>
        const themeToggleBtn = document.getElementById('theme-toggle');
        const themeToggleKnob = document.getElementById('theme-toggle-knob');
        const themeToggleIcon = document.getElementById('theme-toggle-icon');
        const themeToggleText = document.getElementById('theme-toggle-text');

        function updateToggleUI() {
            if (!themeToggleKnob) return;
            if (document.documentElement.classList.contains('dark')) {
                themeToggleKnob.classList.add('translate-x-5');
                themeToggleIcon.className = 'fa-solid fa-sun text-yellow-400';
                themeToggleText.innerText = 'Light Mode';
            } else {
                themeToggleKnob.classList.remove('translate-x-5');
                themeToggleIcon.className = 'fa-solid fa-moon text-slate-400';
                themeToggleText.innerText = 'Dark Mode';
            }
        }
        
        setTimeout(() => {
            updateToggleUI();
            if(themeToggleBtn) {
                themeToggleBtn.addEventListener('click', function() {
                    document.documentElement.classList.toggle('dark');
                    if (document.documentElement.classList.contains('dark')) {
                        localStorage.setItem('color-theme', 'dark');
                    } else {
                        localStorage.setItem('color-theme', 'light');
                    }
                    updateToggleUI();
                });
            }
        }, 100);
    </script>
</body>
</html>