# 🚀 البدء السريع - Mobile Dashboard

## ⚡ التشغيل الفوري (5 دقائق)

### 1. نسخ الملفات
```bash
# نسخ المجلد إلى خادم الويب
cp -r mobile-dashboard /var/www/html/refaq-mobile

# أو رفع الملفات عبر FTP/cPanel
```

### 2. فتح التطبيق
```
https://yourdomain.com/refaq-mobile
```

### 3. إنشاء الأيقونات (اختياري)
افتح في المتصفح:
```
https://yourdomain.com/refaq-mobile/icons/generate-icons.html
```

---

## 🛠️ التطوير المحلي

### تثبيت Node.js (اختياري)
```bash
# تثبيت المتطلبات
npm install

# تشغيل خادم محلي
npm start

# فتح المتصفح على
http://localhost:3000
```

### بدون Node.js
```bash
# استخدام Python
python -m http.server 3000

# أو PHP
php -S localhost:3000

# أو أي خادم ويب آخر
```

---

## 📱 اختبار PWA

### 1. HTTPS مطلوب
- استخدم خادم يدعم HTTPS
- أو استخدم ngrok للاختبار المحلي:
```bash
npx ngrok http 3000
```

### 2. اختبار التثبيت
1. افتح الرابط في Chrome/Edge
2. ابحث عن أيقونة التثبيت في شريط العنوان
3. اضغط "تثبيت"

### 3. اختبار العمل أوفلاين
1. افتح Developer Tools
2. اذهب إلى Network tab
3. اختر "Offline"
4. أعد تحميل الصفحة

---

## 🎨 التخصيص السريع

### تغيير الألوان
في `styles/main.css` السطر 15:
```css
--primary-color: #4ECDC4;  /* غيّر هذا اللون */
```

### تغيير النصوص
في `index.html`:
```html
<h1 class="hero-title">إدارة الفروع</h1>  <!-- غيّر العنوان -->
```

### إضافة صفحة جديدة
1. انسخ `index.html` إلى `new-page.html`
2. غيّر المحتوى
3. أضف رابط في Bottom Navigation

---

## 🔧 ربط API

### استبدال البيانات الوهمية
في `scripts/main.js` السطر 200+:
```javascript
// استبدل هذا
async simulateAPI(endpoint, data) {
    // كود وهمي
}

// بهذا
async callAPI(endpoint, data) {
    const response = await fetch(`/api/${endpoint}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    });
    return response.json();
}
```

---

## 📊 مراقبة الأداء

### Lighthouse Test
```bash
npm run lighthouse
```

### أو يدوياً:
1. افتح Developer Tools
2. اذهب إلى Lighthouse tab
3. اختر "Progressive Web App"
4. اضغط "Generate report"

---

## 🐛 حل المشاكل الشائعة

### Service Worker لا يعمل
```javascript
// تحقق في Console
navigator.serviceWorker.getRegistrations().then(console.log);
```

### الأيقونات مفقودة
```bash
# تحقق من وجود الملفات
ls icons/icon-*.png
```

### لا يعمل على HTTPS
```bash
# استخدم ngrok للاختبار
npx ngrok http 3000
```

---

## 📞 الدعم السريع

### مشكلة في التثبيت؟
1. تأكد من HTTPS ✅
2. تحقق من manifest.json ✅
3. تأكد من وجود الأيقونات ✅

### مشكلة في الأداء؟
1. فعّل Compression في الخادم
2. استخدم CDN للخطوط
3. قلل حجم الصور

### مشكلة في التوافق؟
- Chrome 80+ ✅
- Firefox 75+ ✅  
- Safari 13+ ✅
- Edge 80+ ✅

---

**🎉 مبروك! تطبيقك جاهز للاستخدام**

للمزيد من التفاصيل، راجع `README.md`