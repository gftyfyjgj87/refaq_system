// ===== Service Worker for Mobile Dashboard =====

const CACHE_NAME = 'refaq-mobile-dashboard-v1.0.0';
const STATIC_CACHE = 'refaq-static-v1.0.0';
const DYNAMIC_CACHE = 'refaq-dynamic-v1.0.0';

// Files to cache immediately
const STATIC_FILES = [
    '/',
    '/index.html',
    '/styles/main.css',
    '/scripts/main.js',
    '/manifest.json',
    // Google Fonts
    'https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;800;900&display=swap',
    // Icons (will be created)
    '/icons/icon-72x72.png',
    '/icons/icon-96x96.png',
    '/icons/icon-128x128.png',
    '/icons/icon-144x144.png',
    '/icons/icon-152x152.png',
    '/icons/icon-192x192.png',
    '/icons/icon-384x384.png',
    '/icons/icon-512x512.png'
];

// Dynamic files to cache on request
const DYNAMIC_FILES = [
    // API endpoints (when connected to real backend)
    '/api/',
    '/backend/'
];

// ===== Install Event =====
self.addEventListener('install', (event) => {
    console.log('Service Worker: Installing...');
    
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => {
                console.log('Service Worker: Caching static files');
                return cache.addAll(STATIC_FILES);
            })
            .catch((error) => {
                console.error('Service Worker: Error caching static files', error);
            })
    );
    
    // Force activation
    self.skipWaiting();
});

// ===== Activate Event =====
self.addEventListener('activate', (event) => {
    console.log('Service Worker: Activating...');
    
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cacheName) => {
                    // Delete old caches
                    if (cacheName !== STATIC_CACHE && cacheName !== DYNAMIC_CACHE) {
                        console.log('Service Worker: Deleting old cache', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
    
    // Take control of all pages
    self.clients.claim();
});

// ===== Fetch Event =====
self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);
    
    // Skip non-GET requests
    if (request.method !== 'GET') {
        return;
    }
    
    // Handle different types of requests
    if (STATIC_FILES.some(file => request.url.includes(file))) {
        // Static files - Cache First Strategy
        event.respondWith(cacheFirst(request));
    } else if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/backend/')) {
        // API requests - Network First Strategy
        event.respondWith(networkFirst(request));
    } else if (request.destination === 'image') {
        // Images - Cache First Strategy
        event.respondWith(cacheFirst(request));
    } else {
        // Other requests - Stale While Revalidate Strategy
        event.respondWith(staleWhileRevalidate(request));
    }
});

// ===== Caching Strategies =====

// Cache First Strategy (for static files)
async function cacheFirst(request) {
    try {
        const cachedResponse = await caches.match(request);
        if (cachedResponse) {
            return cachedResponse;
        }
        
        const networkResponse = await fetch(request);
        if (networkResponse.ok) {
            const cache = await caches.open(STATIC_CACHE);
            cache.put(request, networkResponse.clone());
        }
        
        return networkResponse;
    } catch (error) {
        console.error('Cache First Strategy failed:', error);
        return new Response('Offline - Content not available', {
            status: 503,
            statusText: 'Service Unavailable'
        });
    }
}

// Network First Strategy (for API requests)
async function networkFirst(request) {
    try {
        const networkResponse = await fetch(request);
        
        if (networkResponse.ok) {
            const cache = await caches.open(DYNAMIC_CACHE);
            cache.put(request, networkResponse.clone());
        }
        
        return networkResponse;
    } catch (error) {
        console.log('Network failed, trying cache:', error);
        
        const cachedResponse = await caches.match(request);
        if (cachedResponse) {
            return cachedResponse;
        }
        
        // Return offline response for API requests
        return new Response(JSON.stringify({
            error: 'Offline',
            message: 'لا يوجد اتصال بالإنترنت',
            offline: true
        }), {
            status: 503,
            headers: {
                'Content-Type': 'application/json'
            }
        });
    }
}

// Stale While Revalidate Strategy (for other requests)
async function staleWhileRevalidate(request) {
    const cache = await caches.open(DYNAMIC_CACHE);
    const cachedResponse = await cache.match(request);
    
    const fetchPromise = fetch(request).then((networkResponse) => {
        if (networkResponse.ok) {
            cache.put(request, networkResponse.clone());
        }
        return networkResponse;
    }).catch(() => {
        // Return cached response if network fails
        return cachedResponse;
    });
    
    // Return cached response immediately if available, otherwise wait for network
    return cachedResponse || fetchPromise;
}

// ===== Background Sync =====
self.addEventListener('sync', (event) => {
    console.log('Service Worker: Background sync triggered');
    
    if (event.tag === 'background-sync') {
        event.waitUntil(doBackgroundSync());
    }
});

async function doBackgroundSync() {
    try {
        // Sync offline data when connection is restored
        console.log('Service Worker: Performing background sync');
        
        // Here you would sync any offline data
        // For example: pending form submissions, cached API calls, etc.
        
        // Notify the main thread that sync is complete
        const clients = await self.clients.matchAll();
        clients.forEach(client => {
            client.postMessage({
                type: 'SYNC_COMPLETE',
                message: 'تم مزامنة البيانات بنجاح'
            });
        });
    } catch (error) {
        console.error('Background sync failed:', error);
    }
}

// ===== Push Notifications =====
self.addEventListener('push', (event) => {
    console.log('Service Worker: Push notification received');
    
    const options = {
        body: event.data ? event.data.text() : 'إشعار جديد من نظام إدارة الفروع',
        icon: '/icons/icon-192x192.png',
        badge: '/icons/icon-72x72.png',
        vibrate: [200, 100, 200],
        dir: 'rtl',
        lang: 'ar',
        tag: 'refaq-notification',
        requireInteraction: true,
        actions: [
            {
                action: 'view',
                title: 'عرض',
                icon: '/icons/action-view.png'
            },
            {
                action: 'dismiss',
                title: 'إغلاق',
                icon: '/icons/action-dismiss.png'
            }
        ]
    };
    
    event.waitUntil(
        self.registration.showNotification('نظام إدارة الفروع', options)
    );
});

// ===== Notification Click Handler =====
self.addEventListener('notificationclick', (event) => {
    console.log('Service Worker: Notification clicked');
    
    event.notification.close();
    
    if (event.action === 'view') {
        // Open the app
        event.waitUntil(
            clients.openWindow('/')
        );
    } else if (event.action === 'dismiss') {
        // Just close the notification
        return;
    } else {
        // Default action - open the app
        event.waitUntil(
            clients.openWindow('/')
        );
    }
});

// ===== Message Handler =====
self.addEventListener('message', (event) => {
    console.log('Service Worker: Message received', event.data);
    
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
    
    if (event.data && event.data.type === 'GET_VERSION') {
        event.ports[0].postMessage({
            version: CACHE_NAME
        });
    }
});

// ===== Error Handler =====
self.addEventListener('error', (event) => {
    console.error('Service Worker: Error occurred', event.error);
});

// ===== Unhandled Rejection Handler =====
self.addEventListener('unhandledrejection', (event) => {
    console.error('Service Worker: Unhandled promise rejection', event.reason);
});

// ===== Utility Functions =====

// Clean old caches
async function cleanOldCaches() {
    const cacheNames = await caches.keys();
    const oldCaches = cacheNames.filter(name => 
        name !== STATIC_CACHE && name !== DYNAMIC_CACHE
    );
    
    return Promise.all(
        oldCaches.map(name => caches.delete(name))
    );
}

// Get cache size
async function getCacheSize() {
    const cacheNames = await caches.keys();
    let totalSize = 0;
    
    for (const name of cacheNames) {
        const cache = await caches.open(name);
        const keys = await cache.keys();
        
        for (const request of keys) {
            const response = await cache.match(request);
            if (response) {
                const blob = await response.blob();
                totalSize += blob.size;
            }
        }
    }
    
    return totalSize;
}

// Preload critical resources
async function preloadCriticalResources() {
    const cache = await caches.open(STATIC_CACHE);
    
    const criticalResources = [
        '/',
        '/styles/main.css',
        '/scripts/main.js'
    ];
    
    return cache.addAll(criticalResources);
}

console.log('Service Worker: Script loaded successfully');