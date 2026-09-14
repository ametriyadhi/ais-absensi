(function () {
    'use strict';

    function initSidebar() {
        const topbarToggle = document.getElementById('menuButton');
        const sidebarToggle = document.getElementById('sidebarToggleBtn') || document.getElementById('sidebarCloseBtn');
        const backdrop = document.getElementById('sidebarBackdrop');

        const isDesktop = () => window.innerWidth > 768;

        // Terapkan status collapsed awal dari localStorage pada desktop
        if (isDesktop()) {
            if (localStorage.getItem('sidebar_collapsed') === 'true') {
                document.body.classList.add('sidebar-collapsed');
                document.documentElement.classList.add('sidebar-collapsed');
            } else {
                document.body.classList.remove('sidebar-collapsed');
                document.documentElement.classList.remove('sidebar-collapsed');
            }
        } else {
            // Pada mobile, bersihkan kelas collapsed desktop
            document.body.classList.remove('sidebar-collapsed');
            document.documentElement.classList.remove('sidebar-collapsed');
        }

        function toggle() {
            if (isDesktop()) {
                const isCurrentlyCollapsed = document.body.classList.contains('sidebar-collapsed');
                if (isCurrentlyCollapsed) {
                    document.body.classList.remove('sidebar-collapsed');
                    document.documentElement.classList.remove('sidebar-collapsed');
                    localStorage.setItem('sidebar_collapsed', 'false');
                } else {
                    document.body.classList.add('sidebar-collapsed');
                    document.documentElement.classList.add('sidebar-collapsed');
                    localStorage.setItem('sidebar_collapsed', 'true');
                }
            } else {
                document.body.classList.toggle('sidebar-mobile-open');
            }
        }

        function closeMobile() {
            document.body.classList.remove('sidebar-mobile-open');
        }

        const buttons = [topbarToggle, sidebarToggle].filter(Boolean);
        buttons.forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                toggle();
            });
        });

        if (backdrop) {
            backdrop.addEventListener('click', function (e) {
                e.preventDefault();
                closeMobile();
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && document.body.classList.contains('sidebar-mobile-open')) {
                closeMobile();
            }
        });

        window.addEventListener('resize', function () {
            if (isDesktop() && document.body.classList.contains('sidebar-mobile-open')) {
                closeMobile();
            }
        });

        // Highlight active menu item based on current URL path
        try {
            const currentPath = window.location.pathname.replace(/\/$/, '');
            const navLinks = document.querySelectorAll('nav a');

            navLinks.forEach(link => {
                const linkPath = new URL(link.href, window.location.origin).pathname.replace(/\/$/, '');
                if (currentPath === linkPath || (linkPath !== '/public' && currentPath.startsWith(linkPath))) {
                    link.classList.add('active');
                }
            });
        } catch (err) {
            console.error(err);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSidebar);
    } else {
        initSidebar();
    }
})();
