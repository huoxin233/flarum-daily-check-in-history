import User from 'flarum/common/models/User';

declare module 'flarum/common/models/User' {
  export default interface User {
    checkinCard(): number | undefined;
    canQueryOthersHistory(): boolean | undefined;
  }
}

declare module 'flarum/forum/components/UserPage' {
  export default interface UserPage {
    user: User | null;
    loadUser(username: string): void;
  }
}

declare module 'flarum/forum/components/UserCard' {
  export default interface UserCard {
    attrs: {
      user: User;
      [key: string]: any;
    };
  }
}
