(function(window) {
  window.ls.container.get("view").add({
    selector: "data-paging-next",
    controller: function(element, container, expression, env) {
      let paths = [];
      let limit = env.PAGING_LIMIT;

      let check = function() {
        let offset = parseInt(
          expression.parse(element.dataset["offset"]) || "0"
        );

        paths = paths.concat(expression.getPaths());

        let total = parseInt(expression.parse(element.dataset["total"]) || "0");

        paths = paths.concat(expression.getPaths());

        if (offset + limit >= total) {
          element.disabled = true;
        } else {
          element.disabled = false;
          element.value = offset + limit;
        }
      };

      check();

      for (let i = 0; i < paths.length; i++) {
        let path = paths[i].split(".");

        while (path.length) {
          container.bind(element, path.join("."), check);
          path.pop();
        }
      }
    }
  });
})(window);
