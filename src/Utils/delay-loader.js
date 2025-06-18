(function () {
  function executeDelayedScripts() {
    const delayedScripts = document.querySelectorAll(
      'script[data-delay][type="text/plain"]'
    );

    delayedScripts.forEach((script) => {
      const newScript = document.createElement("script");
      newScript.text = script.textContent;

      for (const attr of script.attributes) {
        if (!["type", "data-delay"].includes(attr.name)) {
          newScript.setAttribute(attr.name, attr.value);
        }
      }

      script.parentNode.replaceChild(newScript, script);
    });
  }

  if ("requestIdleCallback" in window) {
    requestIdleCallback(executeDelayedScripts);
  } else {
    window.addEventListener("load", () => {
      setTimeout(executeDelayedScripts, 0);
    });
  }
})();
