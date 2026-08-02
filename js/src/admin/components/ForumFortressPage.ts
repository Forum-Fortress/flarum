import ExtensionPage, { ExtensionPageAttrs } from 'flarum/admin/components/ExtensionPage';
import m from 'mithril';
import type Mithril from 'mithril';
import ForumFortressControls from './ForumFortressControls';

export default class ForumFortressPage extends ExtensionPage {
  content(vnode: Mithril.VnodeDOM<ExtensionPageAttrs, this>): Mithril.Children {
    return [
      m('.ForumFortressDashboard', m('.container', m(ForumFortressControls))),
      super.content(vnode),
    ];
  }
}
