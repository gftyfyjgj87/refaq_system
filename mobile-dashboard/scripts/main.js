// ===== Mobile Dashboard JavaScript =====

class MobileDashboard {
    constructor() {
        this.init();
        this.bindEvents();
        this.loadTheme();
    }

    init() {
        // Initialize components
        this.themeToggle = document.getElementById('themeToggle');
        this.addBranchBtn = document.getElementById('addBranchBtn');
        this.addBranchModal = document.getElementById('addBranchModal');
        this.closeModal = document.getElementById('closeModal');
        this.cancelBtn = document.getElementById('cancelBtn');
        this.saveBtn = document.getElementById('saveBtn');
        this.searchInput = document.getElementById('searchInput');
        this.branchCards = document.getElementById('branchCards');
        this.notificationBtn = document.getElementById('notificationBtn');
        
        // Set initial theme icon
        this.updateThemeIcon();
        
        console.log('Mobile Dashboard initialized');
    }

    bindEvents() {
        // Theme toggle
        this.themeToggle?.addEventListener('click', () => this.toggleTheme());
        
        // Modal events
        this.addBranchBtn?.addEventListener('click', () => this.openModal());
        this.closeModal?.addEventListener('click', () => this.closeModalHandler());
        this.cancelBtn?.addEventListener('click', () => this.closeModalHandler());
        this.saveBtn?.addEventListener('click', () => this.saveBranch());
        
        // Close modal on overlay click
        this.addBranchModal?.addEventListener('click', (e) => {
            if (e.target === this.addBranchModal) {
                this.closeModalHandler();
            }
        });
        
        // Search functionality
        this.searchInput?.addEventListener('input', (e) => this.handleSearch(e.target.value));
        
        // Notification button
        this.notificationBtn?.addEventListener('click', () => this.showNotifications());
        
        // Branch card actions
        this.bindCardActions();
        
        // Bottom navigation
        this.bindBottomNavigation();
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => this.handleKeyboard(e));
        
        // Touch events for better mobile experience
        this.bindTouchEvents();
    }

    // ===== Theme Management =====
    toggleTheme() {
        const currentTheme = document.documentElement.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        document.documentElement.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);
        
        this.updateThemeIcon();
        this.showToast(`تم التبديل إلى الوضع ${newTheme === 'dark' ? 'الليلي' : 'النهاري'}`);
    }

    loadTheme() {
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
        this.updateThemeIcon();
    }

    updateThemeIcon() {
        const currentTheme = document.documentElement.getAttribute('data-theme');
        const themeIcon = this.themeToggle?.querySelector('.theme-icon');
        
        if (themeIcon) {
            themeIcon.textContent = currentTheme === 'dark' ? '☀️' : '🌙';
        }
    }

    // ===== Modal Management =====
    openModal() {
        this.addBranchModal?.classList.add('active');
        document.body.style.overflow = 'hidden';
        
        // Focus first input
        const firstInput = this.addBranchModal?.querySelector('.form-input');
        setTimeout(() => firstInput?.focus(), 300);
    }

    closeModalHandler() {
        this.addBranchModal?.classList.remove('active');
        document.body.style.overflow = '';
        this.clearForm();
    }

    clearForm() {
        const inputs = this.addBranchModal?.querySelectorAll('.form-input, .form-textarea');
        inputs?.forEach(input => input.value = '');
    }

    saveBranch() {
        const formData = this.getFormData();
        
        if (this.validateForm(formData)) {
            this.createBranchCard(formData);
            this.closeModalHandler();
            this.showToast('تم إضافة الفرع بنجاح');
        }
    }

    getFormData() {
        const inputs = this.addBranchModal?.querySelectorAll('.form-input, .form-textarea');
        const data = {};
        
        inputs?.forEach((input, index) => {
            const keys = ['name', 'code', 'manager', 'address'];
            data[keys[index]] = input.value.trim();
        });
        
        return data;
    }

    validateForm(data) {
        if (!data.name || !data.code || !data.manager) {
            this.showToast('يرجى ملء جميع الحقول المطلوبة', 'error');
            return false;
        }
        
        if (data.code.length < 3) {
            this.showToast('كود الفرع يجب أن يكون 3 أحرف على الأقل', 'error');
            return false;
        }
        
        return true;
    }

    // ===== Branch Card Management =====
    createBranchCard(data) {
        const cardHTML = `
            <div class="branch-card" style="animation-delay: 0.1s;">
                <div class="card-header">
                    <div class="branch-info">
                        <div class="branch-icon">🏢</div>
                        <div class="branch-details">
                            <h3 class="branch-name">${data.name}</h3>
                            <span class="branch-code">${data.code}</span>
                        </div>
                    </div>
                    <div class="status-badge active">نشط</div>
                </div>
                
                <div class="card-body">
                    <div class="branch-manager">
                        <span class="manager-icon">👤</span>
                        <span class="manager-name">${data.manager}</span>
                    </div>
                    
                    ${data.address ? `
                    <div class="branch-address">
                        <span class="address-icon">📍</span>
                        <span class="address-text">${data.address}</span>
                    </div>
                    ` : ''}
                    
                    <div class="branch-stats">
                        <div class="stat-item">
                            <span class="stat-icon">👨‍🏫</span>
                            <span class="stat-number">0</span>
                            <span class="stat-label">معلم</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-icon">👨‍🎓</span>
                            <span class="stat-number">0</span>
                            <span class="stat-label">طالب</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-icon">📚</span>
                            <span class="stat-number">0</span>
                            <span class="stat-label">مجموعة</span>
                        </div>
                    </div>
                </div>
                
                <div class="card-actions">
                    <button class="action-btn edit-btn">تعديل</button>
                    <button class="action-btn view-btn">عرض</button>
                </div>
            </div>
        `;
        
        this.branchCards?.insertAdjacentHTML('afterbegin', cardHTML);
        this.bindCardActions();
        this.updateBranchCount();
    }

    bindCardActions() {
        // Edit buttons
        const editBtns = document.querySelectorAll('.edit-btn');
        editBtns.forEach(btn => {
            btn.removeEventListener('click', this.handleEdit);
            btn.addEventListener('click', this.handleEdit.bind(this));
        });
        
        // View buttons
        const viewBtns = document.querySelectorAll('.view-btn');
        viewBtns.forEach(btn => {
            btn.removeEventListener('click', this.handleView);
            btn.addEventListener('click', this.handleView.bind(this));
        });
        
        // Activate buttons
        const activateBtns = document.querySelectorAll('.activate-btn');
        activateBtns.forEach(btn => {
            btn.removeEventListener('click', this.handleActivate);
            btn.addEventListener('click', this.handleActivate.bind(this));
        });
    }

    handleEdit(e) {
        const card = e.target.closest('.branch-card');
        const branchName = card.querySelector('.branch-name').textContent;
        this.showToast(`تعديل ${branchName}`);
        
        // Here you would typically open an edit modal
        // For demo purposes, we'll just show a toast
    }

    handleView(e) {
        const card = e.target.closest('.branch-card');
        const branchName = card.querySelector('.branch-name').textContent;
        this.showToast(`عرض تفاصيل ${branchName}`);
        
        // Here you would typically navigate to branch details
        // For demo purposes, we'll just show a toast
    }

    handleActivate(e) {
        const card = e.target.closest('.branch-card');
        const statusBadge = card.querySelector('.status-badge');
        const branchName = card.querySelector('.branch-name').textContent;
        
        // Toggle status
        if (statusBadge.classList.contains('inactive')) {
            statusBadge.classList.remove('inactive');
            statusBadge.classList.add('active');
            statusBadge.textContent = 'نشط';
            e.target.textContent = 'إيقاف';
            e.target.classList.remove('activate-btn');
            e.target.classList.add('edit-btn');
            this.showToast(`تم تفعيل ${branchName}`);
        }
    }

    // ===== Search Functionality =====
    handleSearch(query) {
        const cards = document.querySelectorAll('.branch-card');
        let visibleCount = 0;
        
        cards.forEach(card => {
            const branchName = card.querySelector('.branch-name').textContent.toLowerCase();
            const branchCode = card.querySelector('.branch-code').textContent.toLowerCase();
            const managerName = card.querySelector('.manager-name').textContent.toLowerCase();
            
            const isVisible = branchName.includes(query.toLowerCase()) ||
                            branchCode.includes(query.toLowerCase()) ||
                            managerName.includes(query.toLowerCase());
            
            card.style.display = isVisible ? 'block' : 'none';
            if (isVisible) visibleCount++;
        });
        
        this.updateBranchCount(visibleCount);
    }

    updateBranchCount(count) {
        const badge = document.querySelector('.filter-badge .badge-text');
        if (badge) {
            const totalCount = count !== undefined ? count : document.querySelectorAll('.branch-card').length;
            badge.textContent = `${totalCount} فرع`;
        }
    }

    // ===== Notifications =====
    showNotifications() {
        const notifications = [
            'تم إضافة معلم جديد في فرع النزهة',
            'طلب انضمام جديد من طالب',
            'تذكير: اجتماع الإدارة غداً'
        ];
        
        const message = notifications.join('\n• ');
        this.showToast(`الإشعارات:\n• ${message}`, 'info', 5000);
    }

    // ===== Bottom Navigation =====
    bindBottomNavigation() {
        const navItems = document.querySelectorAll('.nav-item');
        
        navItems.forEach(item => {
            item.addEventListener('click', (e) => {
                e.preventDefault();
                
                // Remove active class from all items
                navItems.forEach(nav => nav.classList.remove('active'));
                
                // Add active class to clicked item
                item.classList.add('active');
                
                const label = item.querySelector('.nav-label').textContent;
                
                // Handle "المزيد" button specially
                if (label === 'المزيد') {
                    this.showMoreMenu();
                } else {
                    this.showToast(`انتقال إلى ${label}`);
                }
            });
        });
    }

    // Show More Menu
    showMoreMenu() {
        const moreMenuItems = [
            { icon: '📊', title: 'التقارير', action: () => this.showToast('فتح التقارير') },
            { icon: '⚙️', title: 'الإعدادات', action: () => this.showToast('فتح الإعدادات') },
            { icon: '👥', title: 'المستخدمين', action: () => this.showToast('إدارة المستخدمين') },
            { icon: '💰', title: 'المالية', action: () => this.showToast('النظام المالي') },
            { icon: '📝', title: 'الملاحظات', action: () => this.showToast('الملاحظات') },
            { icon: '🔔', title: 'الإشعارات', action: () => this.showNotifications() },
            { icon: '📞', title: 'الدعم الفني', action: () => this.showToast('التواصل مع الدعم') },
            { icon: '🌙', title: 'تغيير الوضع', action: () => this.toggleTheme() }
        ];

        // Create more menu modal
        const moreModal = document.createElement('div');
        moreModal.className = 'modal-overlay active';
        moreModal.id = 'moreModal';
        
        const menuHTML = `
            <div class="modal more-menu-modal">
                <div class="modal-header">
                    <h2 class="modal-title">المزيد</h2>
                    <button class="modal-close" onclick="this.closest('.modal-overlay').remove()">×</button>
                </div>
                
                <div class="modal-body">
                    <div class="more-menu-grid">
                        ${moreMenuItems.map((item, index) => `
                            <button class="more-menu-item" data-action="${index}">
                                <span class="more-menu-icon">${item.icon}</span>
                                <span class="more-menu-title">${item.title}</span>
                            </button>
                        `).join('')}
                    </div>
                </div>
            </div>
        `;
        
        moreModal.innerHTML = menuHTML;
        document.body.appendChild(moreModal);
        
        // Add event listeners to menu items
        const menuItems = moreModal.querySelectorAll('.more-menu-item');
        menuItems.forEach((item, index) => {
            item.addEventListener('click', () => {
                moreMenuItems[index].action();
                moreModal.remove();
            });
        });
        
        // Close on overlay click
        moreModal.addEventListener('click', (e) => {
            if (e.target === moreModal) {
                moreModal.remove();
            }
        });
    }

    // ===== Touch Events =====
    bindTouchEvents() {
        // Add touch feedback to buttons
        const buttons = document.querySelectorAll('button, .nav-item');
        
        buttons.forEach(button => {
            button.addEventListener('touchstart', () => {
                button.style.transform = 'scale(0.95)';
            });
            
            button.addEventListener('touchend', () => {
                setTimeout(() => {
                    button.style.transform = '';
                }, 150);
            });
        });
        
        // Swipe gestures for cards
        let startX, startY, currentCard;
        
        document.addEventListener('touchstart', (e) => {
            if (e.target.closest('.branch-card')) {
                startX = e.touches[0].clientX;
                startY = e.touches[0].clientY;
                currentCard = e.target.closest('.branch-card');
            }
        });
        
        document.addEventListener('touchmove', (e) => {
            if (!currentCard) return;
            
            const deltaX = e.touches[0].clientX - startX;
            const deltaY = e.touches[0].clientY - startY;
            
            // Horizontal swipe
            if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > 50) {
                currentCard.style.transform = `translateX(${deltaX * 0.3}px)`;
                currentCard.style.opacity = 1 - Math.abs(deltaX) / 300;
            }
        });
        
        document.addEventListener('touchend', () => {
            if (currentCard) {
                currentCard.style.transform = '';
                currentCard.style.opacity = '';
                currentCard = null;
            }
        });
    }

    // ===== Keyboard Shortcuts =====
    handleKeyboard(e) {
        // ESC to close modal
        if (e.key === 'Escape' && this.addBranchModal?.classList.contains('active')) {
            this.closeModalHandler();
        }
        
        // Ctrl/Cmd + K to focus search
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            this.searchInput?.focus();
        }
        
        // Ctrl/Cmd + N to add new branch
        if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
            e.preventDefault();
            this.openModal();
        }
    }

    // ===== Toast Notifications =====
    showToast(message, type = 'success', duration = 3000) {
        // Remove existing toast
        const existingToast = document.querySelector('.toast');
        if (existingToast) {
            existingToast.remove();
        }
        
        // Create toast element
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <div class="toast-content">
                <span class="toast-icon">${this.getToastIcon(type)}</span>
                <span class="toast-message">${message}</span>
            </div>
        `;
        
        // Add toast styles
        Object.assign(toast.style, {
            position: 'fixed',
            top: '80px',
            left: '50%',
            transform: 'translateX(-50%)',
            background: type === 'error' ? '#E74C3C' : type === 'info' ? '#3498DB' : '#2ECC71',
            color: 'white',
            padding: '12px 20px',
            borderRadius: '25px',
            fontSize: '14px',
            fontWeight: '600',
            zIndex: '3000',
            boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
            maxWidth: '90%',
            textAlign: 'center',
            opacity: '0',
            transition: 'all 0.3s ease'
        });
        
        document.body.appendChild(toast);
        
        // Animate in
        setTimeout(() => {
            toast.style.opacity = '1';
            toast.style.transform = 'translateX(-50%) translateY(0)';
        }, 100);
        
        // Remove after duration
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(-50%) translateY(-20px)';
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }

    getToastIcon(type) {
        switch (type) {
            case 'error': return '❌';
            case 'info': return 'ℹ️';
            case 'warning': return '⚠️';
            default: return '✅';
        }
    }

    // ===== Utility Methods =====
    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    // ===== API Simulation =====
    async simulateAPI(endpoint, data = null) {
        // Simulate network delay
        await new Promise(resolve => setTimeout(resolve, 500 + Math.random() * 1000));
        
        // Simulate success/error responses
        if (Math.random() > 0.1) { // 90% success rate
            return {
                success: true,
                data: data,
                message: 'تم بنجاح'
            };
        } else {
            throw new Error('حدث خطأ في الشبكة');
        }
    }
}

// ===== Initialize Dashboard =====
document.addEventListener('DOMContentLoaded', () => {
    // Force show main elements
    const header = document.querySelector('.header');
    const mainContent = document.querySelector('.main-content');
    const bottomNav = document.querySelector('.bottom-nav');
    
    if (header) {
        header.style.display = 'block';
        header.style.visibility = 'visible';
        header.style.opacity = '1';
    }
    
    if (mainContent) {
        mainContent.style.display = 'block';
        mainContent.style.visibility = 'visible';
        mainContent.style.opacity = '1';
    }
    
    if (bottomNav) {
        bottomNav.style.display = 'block';
        bottomNav.style.visibility = 'visible';
        bottomNav.style.opacity = '1';
    }
    
    // Initialize dashboard
    new MobileDashboard();
    
    // Show welcome message
    setTimeout(() => {
        const dashboard = new MobileDashboard();
        dashboard.showToast('مرحباً بك في نظام إدارة الفروع! 🏫', 'success', 2000);
    }, 1000);
});

// ===== Service Worker Registration =====
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js')
            .then(registration => {
                console.log('SW registered: ', registration);
            })
            .catch(registrationError => {
                console.log('SW registration failed: ', registrationError);
            });
    });
}

// ===== PWA Install Prompt =====
let deferredPrompt;

window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    
    // Show install button or banner
    const installBanner = document.createElement('div');
    installBanner.innerHTML = `
        <div style="
            position: fixed;
            bottom: 90px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--primary-color);
            color: white;
            padding: 12px 20px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 600;
            z-index: 2000;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        " onclick="installApp()">
            📱 تثبيت التطبيق
        </div>
    `;
    
    document.body.appendChild(installBanner);
    
    // Remove banner after 10 seconds
    setTimeout(() => {
        installBanner.remove();
    }, 10000);
});

window.installApp = async () => {
    if (deferredPrompt) {
        deferredPrompt.prompt();
        const { outcome } = await deferredPrompt.userChoice;
        console.log(`User response to the install prompt: ${outcome}`);
        deferredPrompt = null;
    }
};

// ===== Performance Monitoring =====
window.addEventListener('load', () => {
    if ('performance' in window) {
        const perfData = performance.getEntriesByType('navigation')[0];
        console.log(`Page load time: ${perfData.loadEventEnd - perfData.loadEventStart}ms`);
    }
});

// ===== Error Handling =====
window.addEventListener('error', (e) => {
    console.error('Global error:', e.error);
    // Here you could send error reports to your analytics service
});

window.addEventListener('unhandledrejection', (e) => {
    console.error('Unhandled promise rejection:', e.reason);
    // Here you could send error reports to your analytics service
});