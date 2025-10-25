(function() {
    const cfg = window.bannerConfig || {};
    const text = cfg.text || "Willkommen auf unserer Seite!";
    const link = cfg.link || "#";
    const color1 = cfg.color1 || "#0078D7";
    const color2 = cfg.color2 || "#00A3FF";

    const banner = document.createElement("div");
    banner.style = `
        position: fixed;
        top: 0; left: 0; width: 100%;
        background: linear-gradient(90deg, ${color1}, ${color2});
        color: white; text-align: center;
        padding: 12px 0; font-family: Arial, sans-serif;
        z-index: 9999; box-shadow: 0 2px 6px rgba(0,0,0,0.3);
    `;
    banner.innerHTML = `
        ${text}
        <a href="${link}" style="color:#fff; text-decoration:underline; margin-left:10px;">Mehr erfahren</a>
        <span id="closeBanner" style="margin-left:20px; cursor:pointer;">✖</span>
    `;
    document.body.prepend(banner);
    document.getElementById("closeBanner").addEventListener("click", () => banner.remove());
    document.body.style.marginTop = "50px";
})();
