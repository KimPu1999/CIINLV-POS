import type { Locale } from './lib/marketplace'

export interface AboutContent {
  eyebrow: string
  title: string
  intro: string
  storyTitle: string
  story: string[]
  missionTitle: string
  mission: string
  visionTitle: string
  vision: string
  valuesTitle: string
  values: Array<{ title: string; body: string }>
  howTitle: string
  steps: Array<{ title: string; body: string }>
  benefitsTitle: string
  benefits: Array<{ title: string; body: string }>
}

export interface ContactContent {
  eyebrow: string
  title: string
  intro: string
  methodsTitle: string
  methodsUnavailable: string
  formTitle: string
  formIntro: string
  faqTitle: string
  faqs: Array<{ question: string; answer: string; link?: { label: string; href: string } }>
  subjects: Array<{ value: string; label: string }>
  reviewNotice: string
}

const enAbout: AboutContent = {
  eyebrow: 'About the marketplace',
  title: 'A calmer way to discover local businesses.',
  intro: 'CIINLV Marketplace brings approved business listings, product discovery, checkout and order follow-up together in one customer-facing experience.',
  storyTitle: 'Our story',
  story: [
    'CIINLV Marketplace is the public shopping experience connected to CIINLV POS businesses. Customers can browse published products and approved stores, select product variants, save items, and continue through the available checkout flow.',
    'The marketplace helps customers connect with participating sellers while keeping product, order, delivery and payment steps visible in one place. Availability, payment verification and delivery progress remain subject to the configured business workflows.',
  ],
  missionTitle: 'Our mission',
  mission: 'Make shopping from participating businesses easier to understand, easier to navigate and more respectful of the customer journey.',
  visionTitle: 'Our vision',
  vision: 'Build a dependable marketplace foundation where customers and businesses can grow their relationships through clear information and practical tools.',
  valuesTitle: 'What guides us',
  values: [
    { title: 'Customer experience', body: 'Clear product information, compact navigation and helpful order visibility.' },
    { title: 'Trust and transparency', body: 'Honest availability, payment and delivery states instead of unsupported promises.' },
    { title: 'Business support', body: 'Tools that help participating businesses present products and manage marketplace orders.' },
    { title: 'Accessible by design', body: 'Responsive interfaces, readable content and useful flows across devices.' },
  ],
  howTitle: 'How the marketplace works',
  steps: [
    { title: 'Browse products and stores', body: 'Explore public listings from approved, active marketplace stores.' },
    { title: 'Select products', body: 'Choose supported variants and add the products you want to your cart.' },
    { title: 'Complete checkout', body: 'Confirm delivery information and use an available payment method.' },
    { title: 'Follow your order', body: 'Review order status and shipment tracking when the seller creates delivery activity.' },
  ],
  benefitsTitle: 'Built for useful discovery',
  benefits: [
    { title: 'Product discovery', body: 'Search, browse categories and explore related products.' },
    { title: 'Multiple sellers', body: 'See participating stores and their public product collections.' },
    { title: 'Account management', body: 'Manage saved addresses, wishlist items and order history.' },
    { title: 'Delivery visibility', body: 'See available shipment and tracking information without guessing.' },
    { title: 'Customer support', body: 'Send a support inquiry when you need help with a marketplace journey.' },
  ],
}

const myAbout: AboutContent = {
  eyebrow: 'Marketplace အကြောင်း',
  title: 'ဒေသခံလုပ်ငန်းများကို ရှာဖွေရန် ပိုမိုရိုးရှင်းသောနည်းလမ်း။',
  intro: 'CIINLV Marketplace သည် ခွင့်ပြုထားသော လုပ်ငန်းများ၏ ပစ္စည်းစာရင်းများ၊ ဆိုင်ရှာဖွေမှု၊ ငွေချေမှုနှင့် အော်ဒါနောက်ဆက်တွဲကို ဖောက်သည်အတွက် တစ်နေရာတည်းတွင် စုစည်းပေးထားပါသည်။',
  storyTitle: 'ကျွန်ုပ်တို့၏အကြောင်း',
  story: [
    'CIINLV Marketplace သည် CIINLV POS လုပ်ငန်းများနှင့် ချိတ်ဆက်ထားသော အများပြည်သူကြည့်ရှုနိုင်သည့် ဈေးဝယ်အတွေ့အကြုံဖြစ်ပါသည်။ ဖောက်သည်များသည် ထုတ်ဝေထားသော ပစ္စည်းများနှင့် ခွင့်ပြုထားသောဆိုင်များကို ကြည့်ရှုနိုင်ပြီး အမျိုးအစားရွေးချယ်ခြင်း၊ ပစ္စည်းသိမ်းခြင်းနှင့် ရရှိနိုင်သော ငွေချေမှုလမ်းကြောင်းကို ဆက်လက်အသုံးပြုနိုင်ပါသည်။',
    'Marketplace သည် ဖောက်သည်များနှင့် ပါဝင်သော ရောင်းချသူများကို ချိတ်ဆက်ပေးပြီး ပစ္စည်း၊ အော်ဒါ၊ ပို့ဆောင်မှုနှင့် ငွေပေးချေမှုအဆင့်များကို တစ်နေရာတည်းတွင် မြင်နိုင်စေရန် ကူညီပေးပါသည်။ ရရှိနိုင်မှု၊ ငွေပေးချေမှုအတည်ပြုချက်နှင့် ပို့ဆောင်မှုအခြေအနေများသည် သတ်မှတ်ထားသော လုပ်ငန်းစဉ်များအပေါ် မူတည်ပါသည်။',
  ],
  missionTitle: 'ကျွန်ုပ်တို့၏ ရည်မှန်းချက်',
  mission: 'ပါဝင်သော လုပ်ငန်းများထံမှ ဈေးဝယ်ခြင်းကို နားလည်ရလွယ်ကူ၊ အသုံးပြုရလွယ်ကူပြီး ဖောက်သည်ခရီးစဉ်ကို လေးစားသော အတွေ့အကြုံအဖြစ် ဖန်တီးရန်။',
  visionTitle: 'ကျွန်ုပ်တို့၏ မျှော်မှန်းချက်',
  vision: 'ရှင်းလင်းသော အချက်အလက်များနှင့် လက်တွေ့အသုံးဝင်သော ကိရိယာများမှတစ်ဆင့် ဖောက်သည်နှင့် လုပ်ငန်းများ၏ ဆက်ဆံရေး တိုးတက်လာစေရန် ယုံကြည်စိတ်ချရသော Marketplace အခြေခံကို တည်ဆောက်ရန်။',
  valuesTitle: 'ကျွန်ုပ်တို့၏ တန်ဖိုးများ',
  values: [
    { title: 'ဖောက်သည်အတွေ့အကြုံ', body: 'ရှင်းလင်းသော ပစ္စည်းအချက်အလက်၊ တိုတောင်းသော လမ်းညွှန်မှုနှင့် အော်ဒါအခြေအနေကို အထောက်အကူပြုစွာ ပြသခြင်း။' },
    { title: 'ယုံကြည်မှုနှင့် ပွင့်လင်းမြင်သာမှု', body: 'အထောက်အထားမရှိသော ကတိများအစား ရရှိနိုင်မှု၊ ငွေပေးချေမှုနှင့် ပို့ဆောင်မှုအခြေအနေကို ရိုးသားစွာ ပြသခြင်း။' },
    { title: 'လုပ်ငန်းများကို ပံ့ပိုးခြင်း', body: 'ပါဝင်သော လုပ်ငန်းများက ပစ္စည်းများတင်ပြခြင်းနှင့် Marketplace အော်ဒါများ စီမံခြင်းကို ကူညီပေးခြင်း။' },
    { title: 'လူတိုင်းအသုံးပြုနိုင်သော ဒီဇိုင်း', body: 'စက်ပစ္စည်းအမျိုးမျိုးတွင် ဖတ်ရှုရလွယ်ကူပြီး အသုံးဝင်သော စနစ်များကို တည်ဆောက်ခြင်း။' },
  ],
  howTitle: 'Marketplace အလုပ်လုပ်ပုံ',
  steps: [
    { title: 'ပစ္စည်းနှင့် ဆိုင်များကို ကြည့်ရှုပါ', body: 'ခွင့်ပြုထားပြီး လက်ရှိအသုံးပြုနေသော Marketplace ဆိုင်များ၏ အများပြည်သူပစ္စည်းများကို ရှာဖွေပါ။' },
    { title: 'ပစ္စည်းရွေးချယ်ပါ', body: 'ရရှိနိုင်သော အမျိုးအစားများကို ရွေးပြီး လိုအပ်သောပစ္စည်းများကို ခြင်းတောင်းထဲ ထည့်ပါ။' },
    { title: 'ငွေချေခြင်း ပြီးစီးပါ', body: 'ပို့ဆောင်မည့်အချက်အလက်ကို အတည်ပြုပြီး ရရှိနိုင်သော ငွေပေးချေမှုနည်းလမ်းကို အသုံးပြုပါ။' },
    { title: 'အော်ဒါကို လိုက်လံကြည့်ရှုပါ', body: 'ရောင်းချသူက ပို့ဆောင်မှုမှတ်တမ်း ဖန်တီးပြီးသောအခါ အော်ဒါနှင့် ခြေရာခံအချက်အလက်ကို ကြည့်ပါ။' },
  ],
  benefitsTitle: 'အသုံးဝင်သော ရှာဖွေမှုအတွက် တည်ဆောက်ထားသည်',
  benefits: [
    { title: 'ပစ္စည်းရှာဖွေမှု', body: 'ရှာဖွေခြင်း၊ အမျိုးအစားကြည့်ခြင်းနှင့် ဆက်စပ်ပစ္စည်းများ ရှာဖွေခြင်း။' },
    { title: 'ရောင်းချသူများစွာ', body: 'ပါဝင်သော ဆိုင်များနှင့် ၎င်းတို့၏ အများပြည်သူပစ္စည်းများကို ကြည့်ရှုခြင်း။' },
    { title: 'အကောင့်စီမံမှု', body: 'သိမ်းထားသောလိပ်စာ၊ နှစ်သက်သောပစ္စည်းနှင့် အော်ဒါမှတ်တမ်းကို စီမံခြင်း။' },
    { title: 'ပို့ဆောင်မှုမြင်သာမှု', body: 'ရရှိနိုင်သော ပို့ဆောင်မှုနှင့် ခြေရာခံအချက်အလက်ကို ရှင်းလင်းစွာ ကြည့်ခြင်း။' },
    { title: 'ဖောက်သည်အကူအညီ', body: 'Marketplace အသုံးပြုမှုအတွက် အကူအညီလိုပါက ဆက်သွယ်မေးမြန်းခြင်း။' },
  ],
}

const enContact: ContactContent = {
  eyebrow: 'Support and questions',
  title: 'How can we help?',
  intro: 'Send a message to the marketplace support team. Your inquiry is recorded securely for review; a response is not promised until an authorized support workflow is configured.',
  methodsTitle: 'Public contact methods',
  methodsUnavailable: 'Choose a business to send your inquiry to its secure support queue. Email notification is attempted only when that business has configured mail delivery.',
  formTitle: 'Send an inquiry',
  formIntro: 'Please avoid sending passwords, payment credentials or other sensitive information.',
  faqTitle: 'Frequently asked questions',
  faqs: [
    { question: 'How can I check my order?', answer: 'Sign in and open your order history to view available order and shipment information.', link: { label: 'View orders', href: '/orders' } },
    { question: 'How do I update my delivery address?', answer: 'Open your customer account and manage saved delivery addresses before checkout.', link: { label: 'Manage account', href: '/account#addresses' } },
    { question: 'Which payment methods are available?', answer: 'Available methods depend on the current marketplace configuration and are shown during checkout.', link: { label: 'Open checkout', href: '/cart' } },
    { question: 'How can I contact a seller?', answer: 'Use the public store details when configured, or send an inquiry with the store and order context.' },
    { question: 'How can I request assistance with an order?', answer: 'Include the order number and a short description in the form above. Do not include passwords or payment credentials.' },
  ],
  subjects: [
    { value: 'general', label: 'General inquiry' }, { value: 'order', label: 'Order support' }, { value: 'payment', label: 'Payment support' }, { value: 'delivery', label: 'Delivery support' }, { value: 'seller', label: 'Seller inquiry' }, { value: 'technical', label: 'Technical support' }, { value: 'other', label: 'Other' },
  ],
  reviewNotice: 'Your inquiry is stored as a pending support record for the selected business. The confirmation will show whether email notification is queued, sent, failed or not configured.',
}

const myContact: ContactContent = {
  eyebrow: 'အကူအညီနှင့် မေးမြန်းမှုများ',
  title: 'မည်သို့ ကူညီပေးရမလဲ။',
  intro: 'Marketplace အကူအညီအဖွဲ့ထံ စာပို့ပါ။ သင့်မေးမြန်းမှုကို စိစစ်ရန် လုံခြုံစွာ သိမ်းဆည်းမည်ဖြစ်ပြီး ခွင့်ပြုထားသော အကူအညီလုပ်ငန်းစဉ် မသတ်မှတ်မချင်း ပြန်ကြားမည်ဟု အာမမခံပါ။',
  methodsTitle: 'အများပြည်သူဆိုင်ရာ ဆက်သွယ်ရန်နည်းလမ်းများ',
  methodsUnavailable: 'မေးမြန်းချက်ကို ရွေးချယ်ထားသော လုပ်ငန်း၏ လုံခြုံသော အကူအညီစာရင်းသို့ ပို့ပါမည်။ လုပ်ငန်းက mail ပို့ခြင်းကို သတ်မှတ်ထားမှသာ အီးမေးလ်အသိပေးချက်ကို ကြိုးစားပို့ပါမည်။',
  formTitle: 'မေးမြန်းချက် ပို့ရန်',
  formIntro: 'စကားဝှက်၊ ငွေပေးချေမှုအချက်အလက် သို့မဟုတ် အခြားလျှို့ဝှက်အချက်အလက်များကို မပို့ပါနှင့်။',
  faqTitle: 'မကြာခဏမေးသော မေးခွန်းများ',
  faqs: [
    { question: 'အော်ဒါကို မည်သို့စစ်ဆေးရမလဲ။', answer: 'ဝင်ရောက်ပြီး အော်ဒါမှတ်တမ်းကို ဖွင့်ကာ ရရှိနိုင်သော အော်ဒါနှင့် ပို့ဆောင်မှုအချက်အလက်ကို ကြည့်ပါ။', link: { label: 'အော်ဒါများကြည့်ရန်', href: '/orders' } },
    { question: 'ပို့ဆောင်မည့်လိပ်စာကို မည်သို့ပြင်ရမလဲ။', answer: 'ငွေမချေမီ အကောင့်မှ သိမ်းထားသော ပို့ဆောင်လိပ်စာများကို စီမံပါ။', link: { label: 'အကောင့်စီမံရန်', href: '/account#addresses' } },
    { question: 'မည်သည့် ငွေပေးချေမှုနည်းလမ်းများ ရရှိနိုင်သနည်း။', answer: 'ရရှိနိုင်သောနည်းလမ်းများသည် Marketplace သတ်မှတ်ချက်အပေါ် မူတည်ပြီး ငွေချေချိန်တွင် ပြသပါမည်။', link: { label: 'ငွေချေခြင်းဖွင့်ရန်', href: '/cart' } },
    { question: 'ရောင်းချသူထံ မည်သို့ဆက်သွယ်ရမလဲ။', answer: 'သတ်မှတ်ထားပါက အများပြည်သူဆိုင်ရာ ဆိုင်အချက်အလက်ကို အသုံးပြုပါ သို့မဟုတ် ဆိုင်နှင့် အော်ဒါအချက်အလက် ထည့်ပြီး မေးမြန်းချက်ပို့ပါ။' },
    { question: 'အော်ဒါအတွက် အကူအညီကို မည်သို့တောင်းရမလဲ။', answer: 'အထက်ပါဖောင်တွင် အော်ဒါနံပါတ်နှင့် အကြောင်းအရာတိုတိုကို ထည့်ပါ။ စကားဝှက်နှင့် ငွေပေးချေမှုအချက်အလက် မထည့်ပါနှင့်။' },
  ],
  subjects: [
    { value: 'general', label: 'အထွေထွေမေးမြန်းချက်' }, { value: 'order', label: 'အော်ဒါအကူအညီ' }, { value: 'payment', label: 'ငွေပေးချေမှုအကူအညီ' }, { value: 'delivery', label: 'ပို့ဆောင်မှုအကူအညီ' }, { value: 'seller', label: 'ရောင်းချသူမေးမြန်းချက်' }, { value: 'technical', label: 'နည်းပညာအကူအညီ' }, { value: 'other', label: 'အခြား' },
  ],
  reviewNotice: 'သင့်မေးမြန်းချက်ကို ရွေးချယ်ထားသော လုပ်ငန်းအတွက် စောင့်ဆိုင်းနေသော အကူအညီမှတ်တမ်းအဖြစ် သိမ်းဆည်းပါမည်။ အတည်ပြုချက်တွင် အီးမေးလ်အသိပေးချက် အခြေအနေကို တိတိကျကျ ပြသပါမည်။',
}

export function aboutContent(locale: Locale): AboutContent { return locale === 'my' ? myAbout : enAbout }
export function contactContent(locale: Locale): ContactContent { return locale === 'my' ? myContact : enContact }
