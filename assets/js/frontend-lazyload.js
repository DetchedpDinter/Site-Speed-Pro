// assets/js/frontend-lazyload.js
document.addEventListener("DOMContentLoaded", function () {
  if (!("IntersectionObserver" in window)) {
    // Fallback: load all images immediately if no IO support
    const lazyImagesFallback = document.querySelectorAll(
      "img.sitespeedpro-lazy[data-src]"
    );
    lazyImagesFallback.forEach((img) => {
      img.src = img.getAttribute("data-src");
      img.removeAttribute("data-src");
      img.classList.remove("sitespeedpro-lazy");
    });
    return;
  }

  const lazyImages = document.querySelectorAll(
    "img.sitespeedpro-lazy[data-src]"
  );
  const observer = new IntersectionObserver(function (
    entries,
    observerInstance
  ) {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        const img = entry.target;
        img.src = img.getAttribute("data-src");
        img.removeAttribute("data-src");
        img.classList.remove("sitespeedpro-lazy");
        observerInstance.unobserve(img);
      }
    });
  });

  lazyImages.forEach((img) => observer.observe(img));
});
