document.addEventListener("DOMContentLoaded", init);

function init() {
    console.log("Workout Tracker initialized.");
    if (!localStorage.getItem("userToken") && window.location.pathname !== "/login.html") {
        console.log("User not logged in. Redirecting to login page.");
        window.location.href = "login.html";
        return;
    } else {
        console.log("User is logged in. Loading workouts...");

    }    
    // - Load workouts
    // - Set up event listeners
}