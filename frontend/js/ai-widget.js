/**
 * Floating AI assistant chat widget (use case: Ask AI Assistant).
 * Include on any employee page after api.js: <script src="../js/ai-widget.js"></script>
 */
(function () {
  function el(html) {
    const t = document.createElement('template');
    t.innerHTML = html.trim();
    return t.content.firstChild;
  }

  function mount() {
    const toggle = el(`<button id="ai-widget-toggle" title="Ask the NovaHR AI Assistant">💬</button>`);
    const panel = el(`
      <div id="ai-widget">
        <div class="ai-head">NovaHR AI Assistant</div>
        <div class="ai-body" id="ai-body"></div>
        <form class="ai-foot" id="ai-form">
          <input type="text" id="ai-input" placeholder="Ask about leave, payroll, hours..." autocomplete="off" required />
          <button class="btn small" type="submit">Send</button>
        </form>
      </div>
    `);
    document.body.appendChild(toggle);
    document.body.appendChild(panel);

    const body = panel.querySelector('#ai-body');
    const form = panel.querySelector('#ai-form');
    const input = panel.querySelector('#ai-input');
    let greeted = false;

    function addMsg(text, who) {
      const div = document.createElement('div');
      div.className = 'ai-msg ' + who;
      div.textContent = text;
      body.appendChild(div);
      body.scrollTop = body.scrollHeight;
    }

    toggle.addEventListener('click', () => {
      panel.classList.toggle('open');
      if (panel.classList.contains('open') && !greeted) {
        greeted = true;
        addMsg("Hi! I'm the NovaHR assistant. Ask me about leave policy, working hours, payroll dates, or the company — or say \"onboarding\" for a new-hire walkthrough.", 'bot');
      }
    });

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const q = input.value.trim();
      if (!q) return;
      addMsg(q, 'user');
      input.value = '';
      input.disabled = true;

      try {
        if (/onboard/i.test(q)) {
          const { steps } = await Api.get('/ai/onboarding');
          if (!steps.length) {
            addMsg("No onboarding material has been published yet — ask HR directly.", 'bot');
          } else {
            addMsg(`Here's your onboarding walkthrough (${steps.length} topics):`, 'bot');
            steps.forEach((s) => addMsg(`• ${s.title}: ${s.content}`, 'bot'));
          }
        } else {
          const { answer } = await Api.post('/ai/ask', { question: q });
          addMsg(answer, 'bot');
        }
      } catch (err) {
        addMsg('Sorry, something went wrong reaching the assistant: ' + err.message, 'bot');
      } finally {
        input.disabled = false;
        input.focus();
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
})();
