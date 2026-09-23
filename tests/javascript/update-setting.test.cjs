const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {join} = require('node:path');
const {test} = require('node:test');
const vm = require('node:vm');

function fixture() {
    let definition;
    let read;
    const writes = [];
    const attributes = new Map([['data-setting', 'security']]);
    const input = {
        checked: false,
        disabled: false,
        getAttribute: (key) => attributes.get(key),
        setAttribute: (key, value) => attributes.set(key, value),
        removeAttribute: (key) => attributes.delete(key)
    };
    const Ajax = {
        get: (name, callback, options) => { read = {name, callback, options}; },
        post: (name, callback, options) => {
            writes.push({name, options});
            callback({security: {active: options.active === 1, exists: true}});
        }
    };
    vm.runInNewContext(readFileSync(join(__dirname, '../../bin/controls/UpdateSetting.js'), 'utf8'), {
        define: (name, dependencies, factory) => { definition = factory({}, Ajax); },
        Class: function (value) { return value; }
    });
    const control = Object.assign(Object.create(definition), {
        parent() {},
        addEvents(events) { this.events = events; },
        getElm: () => input,
        getId: () => 42
    });
    control.initialize({});
    control.events.onImport();

    return {
        input, control, writes,
        respond: (active, exists = true) => read.callback({security: {active, exists}}),
        fail: (error) => read.options.onError(error)
    };
}

test('an active cron is checked and an inactive cron is unchecked after loading', async () => {
    for (const active of [true, false]) {
        const f = fixture();
        assert.equal(f.input.disabled, true);
        f.respond(active);
        await f.control.$loaded;
        assert.equal(f.input.checked, active);
        assert.equal(f.input.disabled, false);
    }
});

test('saving a changed checkbox switches the cron in both directions', async () => {
    for (const active of [true, false]) {
        const f = fixture();
        f.respond(!active);
        await f.control.$loaded;
        f.input.checked = active;
        await f.control.save();
        assert.equal(f.writes.length, 1);
        assert.equal(f.writes[0].options.setting, 'security');
        assert.equal(f.writes[0].options.active, active ? 1 : 0);
        await f.control.save();
        assert.equal(f.writes.length, 1);
    }
});

test('unchanged settings do not overwrite a cron changed elsewhere', async () => {
    const f = fixture();
    f.respond(true);
    await f.control.save();
    assert.equal(f.writes.length, 0);
});

test('saving creates a missing inactive cron without enabling it', async () => {
    const f = fixture();
    f.respond(false, false);
    await f.control.save();
    assert.equal(f.writes.length, 1);
    assert.equal(f.writes[0].options.active, 0);
});

test('saving waits for the actual cron status', async () => {
    const f = fixture();
    const saved = f.control.save();
    assert.equal(f.writes.length, 0);
    f.respond(true);
    await saved;
    assert.equal(f.writes.length, 0);
});

test('a failed status request keeps the checkbox disabled and prevents writes', async () => {
    const f = fixture();
    f.fail(new Error('status unavailable'));
    await assert.rejects(f.control.save(), /status unavailable/);
    assert.equal(f.input.disabled, true);
    assert.equal(f.writes.length, 0);
});
