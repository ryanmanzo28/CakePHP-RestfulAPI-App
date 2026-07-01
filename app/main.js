
async function init() {
    console.log("Workout Tracker initialized.");

    const token = localStorage.getItem("jwt") ?? '';
    const pathname = window.location.pathname || '';
    const isLoginPage = pathname.endsWith('login.html');
    const isDashboardPage = pathname.endsWith('dashboard.html');

    if (!token && !isLoginPage) {
        console.log("User not logged in. Redirecting to login page.");
        window.location.href = "pages/login.html";
        return;
    }

    if (token) {
        console.log("User token found in localStorage.");
        // If we are on the login page, send user to dashboard
        if (isLoginPage) {
            window.location.href = "pages/dashboard.html";
            return;
        }
        // Validate token; loginCheck will redirect to login on failure
        try {
            await loginCheck();
        } catch (e) {
            console.warn('Token validation error', e);
        }
    } else {
        console.log("No user token found in localStorage.");
    }
}
    


async function hashPassword(plainText) {
    // Use Web Crypto API to compute a SHA-256 hash and return hex string
    if (!plainText) return '';
    const enc = new TextEncoder();
    const data = enc.encode(plainText);
    const hashBuffer = await crypto.subtle.digest('SHA-256', data);
    const hashArray = Array.from(new Uint8Array(hashBuffer));
    const hashHex = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
    return hashHex;
}
async function loadWorkouts() {
   let hashed = localStorage.getItem('passwordHash') || '';
   const username = localStorage.getItem('username') || '';
   // Fallback: if raw password stored (not recommended), compute the SHA
   if (!hashed) {
       const pass = localStorage.getItem('password') || '';
       if (pass && username) {
           hashed = await hashPassword(pass + username.toLowerCase());
       }
   }
   if (!hashed) {
       throw new Error('Missing password hash; please sign in again.');
   }
   try {
    const response = await fetch(`/api/workouts?hash=${encodeURIComponent(hashed)}`, {
        headers: {
            'Authorization': `Bearer ${localStorage.getItem('jwt') || ''}`,
            'Accept': 'application/json'
        }
    });
    if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
    }
    const workouts = await response.json();
    console.log("Workouts loaded successfully:", workouts);
    return workouts
    // Here you would typically update the DOM to display the workouts
   } catch (error) {
    console.error("Failed to load workouts:", error);

   }
}
async function loginCheck() {
    const token = localStorage.getItem("jwt");

    if (!token) {
        window.location.href = "pages/login.html";
        return;
    }

    const response = await fetch("/api/auth/check", {
        method: "POST",
        headers: {
            "Authorization": `Bearer ${token}`,
            "Content-Type": "application/json",
            "Accept": "application/json"
        },
        body: JSON.stringify({})
    });

    if (!response.ok) {
        localStorage.removeItem('jwt');
        window.location.href = "pages/login.html";
        return;
    }

    const data = await response.json().catch(() => null);
    if (!data || !data.valid) {
        localStorage.removeItem('jwt');
        window.location.href = "pages/login.html";
    }
}

// Run init on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    void init();
});
