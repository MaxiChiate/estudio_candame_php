document.addEventListener("DOMContentLoaded", function () {
    var toggle = document.querySelector(".navToggle");
    var nav = document.getElementById("mainNav");
    var navLinks = document.querySelectorAll(".mainMenu a[data-nav]");

    if (toggle && nav) {
        toggle.addEventListener("click", function () {
            var isOpen = nav.classList.toggle("open");
            toggle.setAttribute("aria-expanded", isOpen ? "true" : "false");
        });

        navLinks.forEach(function (link) {
            link.addEventListener("click", function () {
                nav.classList.remove("open");
                toggle.setAttribute("aria-expanded", "false");
            });
        });
    }

    if (navLinks.length && "IntersectionObserver" in window) {
        var sections = Array.from(document.querySelectorAll("section[id]"));
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) {
                    return;
                }
                navLinks.forEach(function (link) {
                    link.classList.toggle("active", link.getAttribute("data-nav") === entry.target.id);
                });
            });
        }, { rootMargin: "-45% 0px -50% 0px" });

        sections.forEach(function (section) {
            observer.observe(section);
        });
    }
});
