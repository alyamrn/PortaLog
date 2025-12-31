
document.addEventListener("DOMContentLoaded", () => {
  const dropdown = document.querySelector(".dropdown");
  const toggle = document.querySelector(".dropdown-toggle");

  // Restore state from localStorage
  if (localStorage.getItem("dropdownOpen") === "true") {
    dropdown.classList.add("active");
  }

  // Toggle on click
  toggle.addEventListener("click", (e) => {
    e.preventDefault();
    dropdown.classList.toggle("active");

    // Save state
    localStorage.setItem("dropdownOpen", dropdown.classList.contains("active"));
  });
});

