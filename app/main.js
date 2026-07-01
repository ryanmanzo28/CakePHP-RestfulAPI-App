
async function init() {
    console.log("Workout Tracker initialized.");

    const token = localStorage.getItem("jwt") ?? '';
    const pathname = window.location.pathname || '';
    const isLoginPage = pathname.endsWith('login.html') || pathname.endsWith('register.html');
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
   const token = localStorage.getItem('jwt') || '';
   if (!token) {
       throw new Error('Not authenticated');
   }

   try {
    const response = await fetch(`/api/workouts`, {
        headers: {
            'Authorization': `Bearer ${token}`,
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

async function createAccount(username, email, password) {
    const hashedPassword = await hashPassword(password + email.toLowerCase());
    const response = await fetch("/api/auth/register", {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "Accept": "application/json"
        },
        body: JSON.stringify({ username, email, password: hashedPassword })
    });

    const text = await response.text();
    let data = null;
    try {
        data = text ? JSON.parse(text) : null;
    } catch (e) {
        // If parsing failed (e.g. PHP warnings prepended), try to extract the JSON object
        data = null;
        if (text) {
            const m = text.match(/(\{[\s\S]*\})/);
            if (m && m[1]) {
                try { data = JSON.parse(m[1]); } catch (ee) { /* ignore */ }
            }
        }
        // fallback to raw text message
        if (!data) data = { message: text };
    }

    if (!response.ok) {
        const msg = data && (data.error || data.message) ? (data.error || data.message) : `HTTP error ${response.status}`;
        const err = new Error(msg);
        err.status = response.status;
        throw err;
    }

    // Ensure callers always receive an object with a `message` property
    if (!data) {
        data = { message: response.status === 201 ? 'User created' : 'OK' };
    }

    return data;
}

/**
 * Add a new workout via the API. Falls back to returning a local object if API not available.
 * Returns the created workout object.
 */
async function addWorkout({ title, date, duration, notes } = {}) {
    const token = localStorage.getItem('jwt') || '';
    if (!token) throw new Error('Not authenticated');

    const body = { title, date, duration, notes };
    try {
        const res = await fetch('/api/workouts', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'Authorization': `Bearer ${token}`,
            },
            body: JSON.stringify(body),
        });

        if (!res.ok) {
            // if server rejects, surface message
            const text = await res.text().catch(() => '');
            let data = null;
            try { data = text ? JSON.parse(text) : null; } catch (e) { }
            const msg = data && (data.error || data.message) ? (data.error || data.message) : `HTTP ${res.status}`;
            throw new Error(msg);
        }

        const created = await res.json().catch(() => null);
        return created || { id: Date.now(), title, date, duration, notes };
    } catch (err) {
        // Fallback: return a client-side object so UI can update optimistically
        return { id: `local-${Date.now()}`, title, date, duration, notes, _local: true };
    }
}

// Expose for pages to call directly
window.addWorkout = addWorkout;