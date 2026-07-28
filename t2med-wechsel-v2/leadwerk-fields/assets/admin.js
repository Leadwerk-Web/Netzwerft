(function ($) {
    "use strict";

    $(document).on("click", "[data-leadwerk-add]", function () {
        const repeater = this.closest("[data-leadwerk-repeater]");
        const rows = repeater.querySelector(".leadwerk-repeater__rows");
        const index = Number.parseInt(repeater.dataset.nextIndex || "0", 10);
        repeater.dataset.nextIndex = String(index + 1);
        const html = repeater
            .querySelector("template[data-leadwerk-template]")
            .innerHTML.replaceAll("__INDEX__", String(index));
        rows.insertAdjacentHTML("beforeend", html);
    });

    $(document).on("click", "[data-leadwerk-remove]", function () {
        this.closest(".leadwerk-repeater__row").remove();
    });

    $(document).on("click", "[data-leadwerk-image-select]", function () {
        const wrapper = this.closest("[data-leadwerk-image]");
        const frame = wp.media({
            title: "Bild wählen",
            button: { text: "Bild übernehmen" },
            library: { type: "image" },
            multiple: false,
        });
        frame.on("select", function () {
            const attachment = frame.state().get("selection").first().toJSON();
            wrapper.querySelector("[data-leadwerk-image-id]").value = attachment.id;
            const preview = wrapper.querySelector("[data-leadwerk-image-preview]");
            preview.src = attachment.sizes?.medium?.url || attachment.url;
            preview.hidden = false;
            wrapper.querySelector("[data-leadwerk-image-remove]").hidden = false;
        });
        frame.open();
    });

    $(document).on("click", "[data-leadwerk-image-remove]", function () {
        const wrapper = this.closest("[data-leadwerk-image]");
        wrapper.querySelector("[data-leadwerk-image-id]").value = "";
        wrapper.querySelector("[data-leadwerk-image-preview]").hidden = true;
        this.hidden = true;
    });
})(jQuery);
