// Init

window.ls.error = function () {
  return function (error) {
    window.console.error(error);

    if (window.location.pathname !== '/console') {
      window.location = '/console';
    }
  };
};

window.addEventListener("error", function (event) {
  console.error("ERROR-EVENT:", event.error.message, event.error.stack);
});

document.addEventListener("account.deleteSession", function () {
  window.location = "/auth/signin";
});

document.addEventListener("account.create", function () {
  let container = window.ls.container;
  let form = container.get('serviceForm');
  let sdk = container.get('console');

  let promise = sdk.account.createSession(form.email, form.password);

  container.set("serviceForm", {}, true, true); // Remove sensitive data when not needed

  promise.then(function () {
    var subscribe = document.getElementById('newsletter').checked;
    if (subscribe) {
      let alerts = container.get('alerts');
      let loaderId = alerts.add({ text: 'Loading...', class: "" }, 0);
      fetch('https://appwrite.io/v1/newsletter/subscribe', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          name: form.name,
          email: form.email,
        }),
      }).finally(function () {
        alerts.remove(loaderId);
        window.location = '/console';
      });
    } else {
      window.location = '/console';
    }
  }, function (error) {
    window.location = '/auth/signup?failure=1';
  });
});
window.addEventListener("load", async () => {
  const bars = 12;
  const realtime = window.ls.container.get('realtime');
  const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
  let current = {};
  window.ls.container.get('console').subscribe(['project', 'console'], response => {
    if (response.events.includes('stats.connections')) {
      for (let project in response.payload) {
        current[project] = response.payload[project] ?? 0;
      }

      return;
    }

    if (response.events.includes('collections.*.attributes.*')) {
      document.dispatchEvent(new CustomEvent('database.createAttribute'));

      return;
    }

    if (response.events.includes('collections.*.indexes.*')) {
      document.dispatchEvent(new CustomEvent('database.createIndex'));

      return;
    }

    if (response.events.includes('functions.*.deployments.*')) {
      document.dispatchEvent(new CustomEvent('functions.createDeployment'));

      return;
    }

    if (response.events.includes('functions.*.executions.*')) {
      document.dispatchEvent(new CustomEvent('functions.createExecution'));

      return;
    }
  });

  while (true) {
    let newHistory = {};
    for (const project in current) {
      let history = realtime?.history ?? {};

      if (!(project in history)) {
        history[project] = new Array(bars).fill({
          percentage: 0,
          value: 0
        });
      }

      history = history[project];
      history.push({
        percentage: 0,
        value: current[project]
      });
      if (history.length >= bars) {
        history.shift();
      }

      const highest = history.reduce((prev, curr) => {
        return (curr.value > prev) ? curr.value : prev;
      }, 0);

      history = history.map(({ percentage, value }) => {
        createdHistory = true;
        percentage = value === 0 ? 0 : ((Math.round((value / highest) * 10) / 10) * 100);
        if (percentage > 100) percentage = 100;
        else if (percentage == 0 && value != 0) percentage = 5;

        return {
          percentage: percentage,
          value: value
        };
      })
      newHistory[project] = history;
    }

    let currentSnapshot = { ...current };
    for (let index = .1; index <= 1; index += .05) {
      let currentTransition = { ...currentSnapshot };
      for (const project in current) {
        if (project in newHistory) {
          let base = newHistory[project][bars - 2].value;
          let cur = currentSnapshot[project];
          let offset = (cur - base) * index;
          currentTransition[project] = base + Math.floor(offset);
        }
      }

      realtime.setCurrent(currentTransition);
      await sleep(250);
    }

    realtime.setHistory(newHistory);
  }
});

/**
 * Method to add attribute for the UI on array attributes.
 * 
 * Needs to be global - since client side routing will break it.
 * @param {*} doc 
 * @param {*} key 
 * @returns 
 */
function addAttribute(doc, key) {
  if (!Array.isArray(doc[key])) {
      doc[key] = [];
  }
  doc[key].push(null);
  return doc;
}