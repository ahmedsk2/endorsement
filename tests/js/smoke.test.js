import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import { defineComponent, h } from 'vue';

const Hello = defineComponent({
    props: { name: { type: String, default: 'world' } },
    render() {
        return h('span', `hello ${this.name}`);
    },
});

describe('vitest + vue wiring', () => {
    it('mounts a component and renders props', () => {
        const wrapper = mount(Hello, { props: { name: 'PICU' } });
        expect(wrapper.text()).toBe('hello PICU');
    });
});
