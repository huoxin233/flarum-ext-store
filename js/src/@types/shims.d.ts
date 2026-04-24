declare global {
  interface StoreItemData {
    id?: number;
    status: number;
    code: string;
    title: string;
    name: string;
    url: string;
    desc: string;
    price: number;
    discountPrice: number;
    stock: number;
    discount: number;
    discountLimit: number;
    discountLimitUnit: string;
    type: 'permanent' | 'limit';
    outtime: number;
    icon: string;
    hide: number;
    repeat: number;
    autoDeduction: number;
    // optional fields present in some contexts
    createdAt?: string;
    enable?: number;
    enableType?: number;
    payAmt?: number;
    className?: string;
    popUp?: string;
  }

  interface StoreApiResource {
    id: string | number;
    attributes: StoreItemData;
  }
}

declare module 'flarum/common/models/Forum' {
  export default interface Forum {
    attribute(key: 'antoinefr-money.moneyname'): string | undefined;
    attribute(key: 'apiUrl'): string;
    attribute<T = unknown>(key: string): T;
  }
}

export {};
